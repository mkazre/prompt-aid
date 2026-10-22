<?php

namespace App\Services\Rides;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRateCard;
use App\Services\Geo\RoutingService;
use App\Support\ShuttleSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * All shuttle money logic in one place (no controller math):
 *
 * UPFRONT FARE (quote)
 *   trip      = base_fare + per_km_rate x road_km + per_minute_rate x road_minutes   (RideRateCard per vehicle type)
 *   multiplier= min(max_multiplier, time_of_day_multiplier x demand_surge_multiplier)  (never below 1.0)
 *   dynamic   = trip x (multiplier - 1)
 *   fare      = max(trip + dynamic, minimum_fare) + booking_fee, rounded to the nearest rand
 *   The quote is signed (HMAC, app key) and valid for shuttle_quote_ttl_minutes; booking with
 *   a valid token locks that exact fare + multiplier on the ride. Expired/mismatched -> re-quote.
 *
 * FINAL FARE (on completion) — Uber-style upfront price with only two adjustments:
 *   1. Waiting: minutes between driver arrival and trip start beyond shuttle_free_wait_minutes,
 *      x shuttle_wait_rate_per_minute (not surged).
 *   2. Significant route change: if the GPS distance actually driven with the patient exceeds the
 *      planned road distance by more than shuttle_route_change_threshold_pct %, the trip is
 *      re-priced on actual km/minutes with the locked multiplier, and the higher of
 *      (upfront, re-priced) is used. A shorter/faster trip never lowers the upfront price.
 *   final = adjusted_trip_fare + waiting_charge
 *
 * CANCELLATION FEE (patient) — see cancellationPolicy().
 */
class PricingService
{
    public function __construct(protected RoutingService $routing) {}

    public function rateCardFor(string $vehicleType): RideRateCard
    {
        return RideRateCard::query()->where('vehicle_type', $vehicleType)->where('is_active', true)->latest('updated_at')->latest('id')->first()
            ?? new RideRateCard(['vehicle_type' => $vehicleType, 'base_fare' => 30, 'per_km_rate' => 12, 'per_minute_rate' => 0, 'minimum_fare' => 30]);
    }

    // ------------------------------------------------------------------
    // Pure calculation (unit-testable, no I/O)
    // ------------------------------------------------------------------

    /**
     * @param  array{base_fare: float|int|string, per_km_rate: float|int|string, per_minute_rate: float|int|string, minimum_fare: float|int|string}  $card
     * @return array<string, mixed>
     */
    public static function calculate(array $card, float $km, float $minutes, float $timeMultiplier, float $surgeMultiplier, float $bookingFee, float $maxMultiplier): array
    {
        $base = (float) $card['base_fare'];
        $distanceCharge = round($km * (float) $card['per_km_rate'], 2);
        $timeCharge = round($minutes * (float) $card['per_minute_rate'], 2);
        $trip = $base + $distanceCharge + $timeCharge;

        $multiplier = round(max(1.0, min(max(1.0, $maxMultiplier), $timeMultiplier * $surgeMultiplier)), 2);
        $dynamic = round($trip * ($multiplier - 1), 2);
        $minimum = (float) $card['minimum_fare'];
        $beforeFee = $trip + $dynamic;
        $minimumApplied = $beforeFee < $minimum;
        $fare = max($beforeFee, $minimum);
        $total = (float) round($fare + max(0.0, $bookingFee));

        return [
            'base_fare' => round($base, 2),
            'distance_km' => round($km, 2),
            'distance_charge' => $distanceCharge,
            'duration_min' => (int) round($minutes),
            'time_charge' => $timeCharge,
            'trip_subtotal' => round($trip, 2),
            'time_multiplier' => round($timeMultiplier, 2),
            'surge_multiplier' => round($surgeMultiplier, 2),
            'multiplier' => $multiplier,
            'dynamic_charge' => $dynamic,
            'minimum_fare' => round($minimum, 2),
            'minimum_applied' => $minimumApplied,
            'booking_fee' => round(max(0.0, $bookingFee), 2),
            'total' => $total,
        ];
    }

    /**
     * Time-of-day / weekday / public-holiday multiplier for a LOCAL time.
     * Bands: [{label, days: [0=Sun..6=Sat], start: "HH:MM", end: "HH:MM", multiplier}].
     * A band whose end <= start runs overnight into the next day.
     * When several apply, the highest wins.
     *
     * @return array{multiplier: float, label: string|null}
     */
    public static function timeMultiplierFor(CarbonInterface $local, array $bands, array $holidays, float $holidayMultiplier): array
    {
        $best = 1.0;
        $label = null;
        $minute = $local->hour * 60 + $local->minute;
        $dow = $local->dayOfWeek;
        $prevDow = ($dow + 6) % 7;

        foreach ($bands as $band) {
            $days = array_map('intval', (array) ($band['days'] ?? []));
            $start = self::minutesOf($band['start'] ?? null);
            $end = self::minutesOf($band['end'] ?? null);
            $mult = (float) ($band['multiplier'] ?? 1);
            if ($start === null || $end === null || $mult <= 0) {
                continue;
            }

            $matches = $end > $start
                ? in_array($dow, $days, true) && $minute >= $start && $minute < $end
                : (in_array($dow, $days, true) && $minute >= $start) || (in_array($prevDow, $days, true) && $minute < $end);

            if ($matches && $mult > $best) {
                $best = $mult;
                $label = (string) ($band['label'] ?? 'Peak time');
            }
        }

        $date = $local->format('Y-m-d');
        foreach ($holidays as $h) {
            $hDate = is_array($h) ? ($h['date'] ?? null) : $h;
            if ($hDate && substr((string) $hDate, 0, 10) === $date && $holidayMultiplier > $best) {
                $best = $holidayMultiplier;
                $label = is_array($h) && ! empty($h['label']) ? (string) $h['label'] : 'Public holiday';
            }
        }

        return ['multiplier' => round($best, 2), 'label' => $label];
    }

    /** Highest step whose ratio threshold is met. Steps: [{ratio, multiplier}]. */
    public static function surgeFromRatio(float $ratio, array $steps): float
    {
        $best = 1.0;
        foreach ($steps as $step) {
            $r = (float) ($step['ratio'] ?? 0);
            $m = (float) ($step['multiplier'] ?? 1);
            if ($r > 0 && $ratio >= $r && $m > $best) {
                $best = $m;
            }
        }

        return round($best, 2);
    }

    /**
     * Cancellation fee amount for a vehicle type / quoted fare, from the
     * global rule or a per-vehicle-type override. Capped at the fare.
     *
     * @param  array<int, array{vehicle_type?: string, type?: string, amount?: float|int|string}>  $overrides
     */
    public static function feeAmount(string $vehicleType, float $fare, string $type, float $fixed, float $percent, array $overrides = []): float
    {
        foreach ($overrides as $o) {
            if (($o['vehicle_type'] ?? null) === $vehicleType) {
                $type = (string) ($o['type'] ?? $type);
                $fixed = $percent = (float) ($o['amount'] ?? 0);
                break;
            }
        }

        $fee = $type === 'percent' ? $fare * $percent / 100 : $fixed;
        if ($fare > 0) {
            $fee = min($fee, $fare);
        }

        return round(max(0.0, $fee), 2);
    }

    protected static function minutesOf(?string $hhmm): ?int
    {
        if (! $hhmm || ! preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    // ------------------------------------------------------------------
    // Live inputs (settings, demand)
    // ------------------------------------------------------------------

    public function localNow(): Carbon
    {
        $tz = ShuttleSettings::str('shuttle_pricing_timezone') ?: 'Africa/Johannesburg';
        try {
            return now()->setTimezone($tz);
        } catch (\Throwable) {
            return now()->setTimezone('Africa/Johannesburg');
        }
    }

    /**
     * Open requests vs available online drivers around a point, over the
     * last N minutes. Cached 60 s per ~2 km cell so every quote in an area
     * sees the same number (and we don't hammer the DB on each keystroke).
     *
     * @return array{ratio: float, requests: int, drivers: int}
     */
    public function demandAround(float $lat, float $lng): array
    {
        $radius = max(0.5, ShuttleSettings::float('shuttle_surge_radius_km'));
        $window = max(1, ShuttleSettings::int('shuttle_surge_window_minutes'));
        $key = sprintf('shuttle:demand:%.2f:%.2f:%s:%d', $lat, $lng, $radius, $window);

        return Cache::remember($key, 60, function () use ($lat, $lng, $radius, $window) {
            $dLat = $radius / 111.0;
            $dLng = $radius / max(1.0, 111.0 * cos(deg2rad($lat)));

            $requests = Ride::query()
                ->where('status', Ride::STATUS_REQUESTED)
                ->where('created_at', '>=', now()->subMinutes($window))
                ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()->addMinutes(30)))
                ->whereBetween('pickup_lat', [$lat - $dLat, $lat + $dLat])
                ->whereBetween('pickup_lng', [$lng - $dLng, $lng + $dLng])
                ->get(['pickup_lat', 'pickup_lng'])
                ->filter(fn ($r) => RoutingService::haversineKm($lat, $lng, (float) $r->pickup_lat, (float) $r->pickup_lng) <= $radius)
                ->count();

            $drivers = DriverProfile::query()
                ->where('availability', DriverProfile::AVAILABLE)
                ->where('status', 'active')
                ->where('location_updated_at', '>=', now()->subMinutes(max(1, ShuttleSettings::int('shuttle_driver_stale_minutes'))))
                ->whereBetween('current_lat', [$lat - $dLat, $lat + $dLat])
                ->whereBetween('current_lng', [$lng - $dLng, $lng + $dLng])
                ->get(['current_lat', 'current_lng'])
                ->filter(fn ($d) => RoutingService::haversineKm($lat, $lng, (float) $d->current_lat, (float) $d->current_lng) <= $radius)
                ->count();

            return [
                'ratio' => round($requests / max(1, $drivers), 2),
                'requests' => $requests,
                'drivers' => $drivers,
            ];
        });
    }

    /**
     * @return array{time_multiplier: float, time_label: string|null, surge_multiplier: float, demand: array{ratio: float, requests: int, drivers: int}|null}
     */
    public function multipliersAt(float $lat, float $lng): array
    {
        $time = self::timeMultiplierFor(
            $this->localNow(),
            ShuttleSettings::json('shuttle_time_bands'),
            ShuttleSettings::json('shuttle_public_holidays'),
            ShuttleSettings::float('shuttle_holiday_multiplier'),
        );

        $demand = null;
        $surge = 1.0;
        if (ShuttleSettings::bool('shuttle_surge_enabled')) {
            $demand = $this->demandAround($lat, $lng);
            $surge = self::surgeFromRatio($demand['ratio'], ShuttleSettings::json('shuttle_surge_steps'));
        }

        return [
            'time_multiplier' => $time['multiplier'],
            'time_label' => $time['label'],
            'surge_multiplier' => $surge,
            'demand' => $demand,
        ];
    }

    // ------------------------------------------------------------------
    // Quotes
    // ------------------------------------------------------------------

    /**
     * Live quotes for every active vehicle type, each with a signed
     * quote_token that locks the price for shuttle_quote_ttl_minutes.
     *
     * Backward compatible keys for the mobile app: vehicle_type, distance_km, eta_minutes, fare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function quotesFor(float $pLat, float $pLng, float $dLat, float $dLng, bool $roadRouting = true): array
    {
        $route = $roadRouting
            ? $this->routing->route($pLat, $pLng, $dLat, $dLng)
            : $this->routing->fallback($pLat, $pLng, $dLat, $dLng);
        $mult = $this->multipliersAt($pLat, $pLng);
        $bookingFee = ShuttleSettings::float('shuttle_booking_fee');
        $maxMult = ShuttleSettings::float('shuttle_max_multiplier');
        $ttl = max(1, ShuttleSettings::int('shuttle_quote_ttl_minutes'));
        $expires = now()->addMinutes($ttl);

        // One quote per vehicle type: if an admin left several active cards for the
        // same type, the most recently edited one wins.
        return RideRateCard::query()->where('is_active', true)->get()
            ->sortByDesc(fn (RideRateCard $c) => [$c->updated_at?->getTimestamp() ?? 0, $c->id])
            ->unique('vehicle_type')
            ->sortBy('base_fare')
            ->values()
            ->map(function (RideRateCard $card) use ($route, $mult, $bookingFee, $maxMult, $expires, $pLat, $pLng, $dLat, $dLng) {
                $b = self::calculate($card->only(['base_fare', 'per_km_rate', 'per_minute_rate', 'minimum_fare']), $route['distance_km'], $route['duration_min'], $mult['time_multiplier'], $mult['surge_multiplier'], $bookingFee, $maxMult);
                $b['time_label'] = $mult['time_label'];
                $b['demand'] = $mult['demand'];
                $b['route_source'] = $route['source'];
                $b['vehicle_type'] = $card->vehicle_type;

                $token = $this->signQuote([
                    'v' => $card->vehicle_type,
                    'p' => [round($pLat, 6), round($pLng, 6)],
                    'd' => [round($dLat, 6), round($dLng, 6)],
                    'f' => $b['total'],
                    'e' => $expires->getTimestamp(),
                    'b' => $b,
                ]);

                return [
                    'vehicle_type' => $card->vehicle_type,
                    'distance_km' => $route['distance_km'],
                    'eta_minutes' => $route['duration_min'],
                    'duration_minutes' => $route['duration_min'],
                    'fare' => $b['total'],
                    'multiplier' => $b['multiplier'],
                    'is_surging' => $b['multiplier'] > 1.0,
                    'surge_label' => $this->surgeLabel($b),
                    'breakdown' => $b,
                    'route_source' => $route['source'],
                    'quote_token' => $token,
                    'quote_expires_at' => $expires->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /** Route geometry for a quote (for the quote-page map). Uses the same cache entry. */
    public function routeFor(float $pLat, float $pLng, float $dLat, float $dLng): array
    {
        return $this->routing->route($pLat, $pLng, $dLat, $dLng);
    }

    public function surgeLabel(array $b): ?string
    {
        if (($b['multiplier'] ?? 1) <= 1.0) {
            return null;
        }
        if (($b['surge_multiplier'] ?? 1) > 1.0) {
            return 'Fares are higher due to demand';
        }

        return 'Peak-time pricing'.(! empty($b['time_label']) ? ' ('.$b['time_label'].')' : '');
    }

    public function signQuote(array $payload): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, (string) config('app.key'));
    }

    /**
     * A valid, unexpired quote for exactly this vehicle type and route, or null.
     *
     * @return array{v: string, p: array, d: array, f: float, e: int, b: array, sig: string}|null
     */
    public function verifyQuote(?string $token, string $vehicleType, float $pLat, float $pLng, float $dLat, float $dLng): ?array
    {
        if (! $token || ! str_contains($token, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        if (! hash_equals(hash_hmac('sha256', $body, (string) config('app.key')), $sig)) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($payload) || ($payload['e'] ?? 0) < time() || ($payload['v'] ?? null) !== $vehicleType) {
            return null;
        }
        $close = fn (array $a, float $lat, float $lng) => abs(((float) ($a[0] ?? 0)) - $lat) < 0.0005 && abs(((float) ($a[1] ?? 0)) - $lng) < 0.0005;
        if (! $close((array) ($payload['p'] ?? []), $pLat, $pLng) || ! $close((array) ($payload['d'] ?? []), $dLat, $dLng)) {
            return null;
        }
        $payload['sig'] = $sig;

        return $payload;
    }

    /**
     * Fresh single-vehicle quote (used when booking without/with an expired token).
     *
     * @return array<string, mixed>|null
     */
    public function quoteOne(string $vehicleType, float $pLat, float $pLng, float $dLat, float $dLng): ?array
    {
        foreach ($this->quotesFor($pLat, $pLng, $dLat, $dLng) as $q) {
            if ($q['vehicle_type'] === $vehicleType) {
                return $q;
            }
        }

        // Vehicle type without an active rate card: price on the fallback card.
        $route = $this->routing->route($pLat, $pLng, $dLat, $dLng);
        $mult = $this->multipliersAt($pLat, $pLng);
        $b = self::calculate($this->rateCardFor($vehicleType)->only(['base_fare', 'per_km_rate', 'per_minute_rate', 'minimum_fare']), $route['distance_km'], $route['duration_min'], $mult['time_multiplier'], $mult['surge_multiplier'], ShuttleSettings::float('shuttle_booking_fee'), ShuttleSettings::float('shuttle_max_multiplier'));
        $b['time_label'] = $mult['time_label'];
        $b['route_source'] = $route['source'];
        $b['vehicle_type'] = $vehicleType;

        return [
            'vehicle_type' => $vehicleType, 'distance_km' => $route['distance_km'], 'eta_minutes' => $route['duration_min'],
            'fare' => $b['total'], 'multiplier' => $b['multiplier'], 'breakdown' => $b, 'quote_token' => null,
            'quote_expires_at' => now()->addMinutes(max(1, ShuttleSettings::int('shuttle_quote_ttl_minutes')))->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------
    // Final fare
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed> the ride's fare_breakdown with a `final` section
     */
    public function finalFare(Ride $ride): array
    {
        $b = is_array($ride->fare_breakdown) ? $ride->fare_breakdown : [];
        $upfront = (float) ($ride->quoted_fare ?? $ride->fare_estimate ?? 0);

        // 1. Waiting time at pickup.
        $waitMin = 0;
        if ($ride->driver_arrived_at && $ride->started_at) {
            $waitMin = max(0, (int) floor($ride->driver_arrived_at->diffInSeconds($ride->started_at, true) / 60) - max(0, ShuttleSettings::int('shuttle_free_wait_minutes')));
        }
        $waitCharge = round($waitMin * max(0.0, ShuttleSettings::float('shuttle_wait_rate_per_minute')), 2);

        // 2. Significant route change (actual GPS distance with patient on board vs planned road distance).
        $points = $ride->locations()->where('phase', 'to_dropoff')->orderBy('recorded_at')->orderBy('id')->get(['lat', 'lng'])
            ->map(fn ($l) => [(float) $l->lat, (float) $l->lng])->all();
        $actualKm = count($points) > 1 ? round(RoutingService::lengthKm($points), 2) : null;
        $plannedKm = (float) ($ride->route_distance_km ?? $ride->distance_km ?? 0);
        $threshold = max(0.0, ShuttleSettings::float('shuttle_route_change_threshold_pct'));
        $routeChanged = $actualKm !== null && $plannedKm > 0 && $actualKm > $plannedKm * (1 + $threshold / 100);

        $adjusted = $upfront;
        $repriced = null;
        if ($routeChanged) {
            $actualMin = $ride->started_at ? max(1, (int) ceil($ride->started_at->diffInSeconds($ride->completed_at ?? now(), true) / 60)) : (int) ($ride->route_duration_min ?? 0);
            $mult = (float) ($ride->surge_multiplier ?: 1);
            $repriced = self::calculate(
                $this->rateCardFor($ride->vehicle_type)->only(['base_fare', 'per_km_rate', 'per_minute_rate', 'minimum_fare']),
                $actualKm, $actualMin, $mult, 1.0, (float) ($b['booking_fee'] ?? ShuttleSettings::float('shuttle_booking_fee')), max($mult, 1.0),
            );
            $adjusted = max($upfront, (float) $repriced['total']);
        }

        $b['final'] = [
            'upfront_fare' => round($upfront, 2),
            'planned_km' => round($plannedKm, 2),
            'actual_km' => $actualKm,
            'route_changed' => $routeChanged,
            'repriced_total' => $repriced['total'] ?? null,
            'adjusted_trip_fare' => round($adjusted, 2),
            'waiting_minutes_charged' => $waitMin,
            'waiting_charge' => $waitCharge,
            'total' => round($adjusted + $waitCharge, 2),
            'rule' => 'Upfront price; adjusted only for paid waiting time and if the driven route was more than '.$threshold.'% longer than planned.',
        ];

        return $b;
    }

    // ------------------------------------------------------------------
    // Cancellation / no-show
    // ------------------------------------------------------------------

    /**
     * Patient cancellation rule:
     *   - not cancellable once the trip has started (or ended);
     *   - free while no driver is assigned;
     *   - free within shuttle_free_cancel_minutes of booking;
     *   - otherwise a fee applies if the assigned driver has ARRIVED or is
     *     <= shuttle_cancel_fee_eta_minutes away (road ETA);
     *   - fee = fixed amount or % of the quoted fare (per-vehicle override optional), capped at the fare.
     *
     * @return array{allowed: bool, fee: float, reason: string, free_until: string|null}
     */
    public function cancellationPolicy(Ride $ride, ?int $driverEtaMinutes): array
    {
        if (in_array($ride->status, [Ride::STATUS_IN_PROGRESS, Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED], true)) {
            return ['allowed' => false, 'fee' => 0.0, 'reason' => 'This trip can no longer be cancelled.', 'free_until' => null];
        }

        $freeUntil = ($ride->requested_at ?? $ride->created_at ?? now())->copy()->addMinutes(max(0, ShuttleSettings::int('shuttle_free_cancel_minutes')));

        if (! $ride->driver_profile_id) {
            return ['allowed' => true, 'fee' => 0.0, 'reason' => 'No driver has been assigned yet, so cancelling is free.', 'free_until' => null];
        }

        if (now()->lt($freeUntil)) {
            return ['allowed' => true, 'fee' => 0.0, 'reason' => 'You are within the free cancellation window.', 'free_until' => $freeUntil->toIso8601String()];
        }

        $threshold = max(0, ShuttleSettings::int('shuttle_cancel_fee_eta_minutes'));
        $arrived = $ride->status === Ride::STATUS_ARRIVED;
        $close = $driverEtaMinutes !== null && $driverEtaMinutes <= $threshold;

        if (! $arrived && ! $close) {
            return ['allowed' => true, 'fee' => 0.0, 'reason' => "Your driver is more than {$threshold} min away, so cancelling is free.", 'free_until' => null];
        }

        $fee = self::feeAmount(
            (string) $ride->vehicle_type,
            (float) ($ride->quoted_fare ?? $ride->fare_estimate ?? 0),
            ShuttleSettings::str('shuttle_cancel_fee_type'),
            ShuttleSettings::float('shuttle_cancel_fee_amount'),
            ShuttleSettings::float('shuttle_cancel_fee_percent'),
            ShuttleSettings::json('shuttle_cancel_fee_overrides'),
        );

        return [
            'allowed' => true,
            'fee' => $fee,
            'reason' => $arrived ? 'Your driver has already arrived at the pickup point.' : "Your driver is {$driverEtaMinutes} min away.",
            'free_until' => null,
        ];
    }

    public function noShowFee(Ride $ride): float
    {
        return self::feeAmount(
            (string) $ride->vehicle_type,
            (float) ($ride->quoted_fare ?? $ride->fare_estimate ?? 0),
            ShuttleSettings::str('shuttle_no_show_fee_type'),
            ShuttleSettings::float('shuttle_no_show_fee_amount'),
            ShuttleSettings::float('shuttle_no_show_fee_percent'),
        );
    }
}
