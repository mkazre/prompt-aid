<?php

namespace App\Services\Rides;

use App\Contracts\NotificationDispatcherInterface;
use App\Events\RideCancelled;
use App\Events\RideCompleted;
use App\Events\RideStatusChanged;
use App\Models\DriverProfile;
use App\Models\PatientProfile;
use App\Models\Ride;
use App\Models\RideCharge;
use App\Models\RideOffer;
use App\Models\RideRateCard;
use App\Models\RideReview;
use App\Models\RideStatusEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Geo\RoutingService;
use App\Services\Notifications\InAppNotifier;
use App\Support\ShuttleSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ride lifecycle: booking (road route + locked upfront quote), Bolt/Uber
 * style sequential dispatch, driver acceptance, status transitions, trip
 * PIN, completion with final fare, and every cancellation path.
 *
 * DISPATCH (no websockets/queue on shared hosting, so it is lazy):
 *   - Auto-assign contexts (Theme settings -> Ride Dispatch toggles) put the
 *     ride in dispatch_state "searching"; other contexts in "queue" (open
 *     queue drivers can claim — unchanged behaviour).
 *   - advance() offers the ride to ONE best driver at a time: online
 *     (available, location fresh), active, compliant (complianceBlocker),
 *     not on/offered another trip, capability-matched vehicle (hard rule —
 *     see compatibleVehicleTypes), within the search radius, ranked by road
 *     ETA to pickup plus a reliability penalty.
 *   - Offers expire after shuttle_offer_timeout_seconds, then the next driver.
 *     The radius grows linearly from shuttle_dispatch_radius_km to
 *     shuttle_dispatch_max_radius_km over shuttle_dispatch_timeout_minutes.
 *     After shuttle_dispatch_max_attempts offers or that timeout ->
 *     "no_drivers" (patient may retry/cancel; super admins are alerted).
 *   - advance() runs whenever the patient, a driver or the admin board
 *     polls, and from the Tick runner (cron optional).
 */
class RideDispatchService
{
    public function __construct(
        protected NotificationDispatcherInterface $notifier,
        protected PricingService $pricing,
        protected RoutingService $routing,
        protected RideLocationService $locations,
        protected RideChargeService $charges,
    ) {}

    // ------------------------------------------------------------------
    // Backward-compatible helpers
    // ------------------------------------------------------------------

    public function rateCardFor(string $vehicleType): RideRateCard
    {
        return $this->pricing->rateCardFor($vehicleType);
    }

    /** @return array<int, array<string, mixed>> */
    public function quotesFor(float $pickupLat, float $pickupLng, float $dropoffLat, float $dropoffLng, bool $roadRouting = true): array
    {
        return $this->pricing->quotesFor($pickupLat, $pickupLng, $dropoffLat, $dropoffLng, $roadRouting);
    }

    public function estimateFare(float $distanceKm, string $vehicleType = 'sedan', int $etaMinutes = 0): float
    {
        return $this->rateCardFor($vehicleType)->estimate($distanceKm, $etaMinutes);
    }

    public function updateDriverLocation(DriverProfile $driver, float $lat, float $lng, ?float $heading = null, ?float $speed = null): array
    {
        return $this->locations->record($driver, $lat, $lng, $heading, $speed);
    }

    /** Nearest eligible driver (no offer created). Hard vehicle-capability match, max radius. */
    public function findNearestAvailableDriver(float $lat, float $lng, ?string $vehicleType = null): ?DriverProfile
    {
        return $this->eligibleDrivers($vehicleType ?? 'sedan', $lat, $lng, ShuttleSettings::float('shuttle_dispatch_max_radius_km'))->first()['driver'] ?? null;
    }

    /**
     * Hard capability rule. Only standard-vehicle upgrades are allowed
     * (sedan -> suv -> van). Wheelchair-accessible and stretcher rides are
     * never substituted, and those vehicles are not used for other rides'
     * upgrades either (keeps them free for patients who need them).
     *
     * @return array<int, string>
     */
    public static function compatibleVehicleTypes(string $requested): array
    {
        return match ($requested) {
            'sedan' => ['sedan', 'suv', 'van'],
            'suv' => ['suv', 'van'],
            'van' => ['van'],
            default => [$requested],
        };
    }

    // ------------------------------------------------------------------
    // Booking
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $lockedQuote  verified quote payload (PricingService::verifyQuote) to honour
     */
    public function requestRide(
        PatientProfile $patient,
        string $pickupAddress,
        float $pickupLat,
        float $pickupLng,
        string $dropoffAddress,
        float $dropoffLat,
        float $dropoffLng,
        ?int $appointmentId = null,
        string $vehicleType = 'sedan',
        bool $autoAssign = true,
        ?array $lockedQuote = null,
        \DateTimeInterface|string|null $scheduledFor = null,
    ): Ride {
        $route = $this->routing->route($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);

        if ($lockedQuote) {
            $breakdown = (array) ($lockedQuote['b'] ?? []);
            $fare = (float) $lockedQuote['f'];
            $token = (string) ($lockedQuote['sig'] ?? '');
            $expires = now()->setTimestamp((int) $lockedQuote['e']);
        } else {
            $q = $this->pricing->quoteOne($vehicleType, $pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
            $breakdown = (array) ($q['breakdown'] ?? []);
            $fare = (float) ($q['fare'] ?? 0);
            $token = null;
            $expires = null;
        }

        $scheduled = $scheduledFor ? \Illuminate\Support\Carbon::parse($scheduledFor) : null;
        $searchNow = $autoAssign && (! $scheduled || $scheduled->lte(now()->addMinutes(max(0, ShuttleSettings::int('shuttle_scheduled_dispatch_lead_minutes')))));

        $ride = Ride::query()->create([
            'patient_profile_id' => $patient->id,
            'appointment_id' => $appointmentId,
            'vehicle_type' => $vehicleType,
            'pickup_address' => $pickupAddress,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'dropoff_address' => $dropoffAddress,
            'dropoff_lat' => $dropoffLat,
            'dropoff_lng' => $dropoffLng,
            'status' => Ride::STATUS_REQUESTED,
            'distance_km' => $route['distance_km'],
            'eta_minutes' => $route['duration_min'],
            'fare_estimate' => $fare,
            'quoted_fare' => $fare,
            'surge_multiplier' => (float) ($breakdown['multiplier'] ?? 1),
            'quote_token' => $token ? substr($token, 0, 64) : null,
            'quote_expires_at' => $expires,
            'fare_breakdown' => $breakdown,
            'route_distance_km' => $route['distance_km'],
            'route_duration_min' => $route['duration_min'],
            'route_geometry' => $route['geometry'],
            'scheduled_for' => $scheduled,
            'dispatch_state' => $searchNow ? Ride::DISPATCH_SEARCHING : Ride::DISPATCH_QUEUE,
            'dispatch_started_at' => $searchNow ? now() : null,
            'dispatch_attempts' => 0,
        ]);

        $this->logEvent($ride, Ride::STATUS_REQUESTED, $pickupLat, $pickupLng);
        $this->fireStatus($ride, null, Ride::STATUS_REQUESTED, 'patient');

        if ($searchNow) {
            $this->advance($ride);
        }

        return $ride->fresh();
    }

    // ------------------------------------------------------------------
    // Sequential offers
    // ------------------------------------------------------------------

    /**
     * Lazy dispatch step. Safe to call on every poll: cheap when an offer
     * is already pending, serialised per ride with a cache lock.
     */
    public function advance(Ride $ride): Ride
    {
        if ($ride->status !== Ride::STATUS_REQUESTED || $ride->driver_profile_id || $ride->dispatch_state !== Ride::DISPATCH_SEARCHING) {
            return $ride;
        }

        $lock = Cache::lock("ride:{$ride->id}:dispatch", 15);
        if (! $lock->get()) {
            return $ride;
        }

        try {
            $ride->refresh();
            if ($ride->status !== Ride::STATUS_REQUESTED || $ride->driver_profile_id || $ride->dispatch_state !== Ride::DISPATCH_SEARCHING) {
                return $ride;
            }

            $this->expireOffers($ride);

            if ($ride->offers()->where('status', RideOffer::PENDING)->exists()) {
                return $ride;
            }

            $timeoutSec = max(30, ShuttleSettings::int('shuttle_dispatch_timeout_minutes') * 60);
            $started = $ride->dispatch_started_at ?? $ride->requested_at ?? now();
            $elapsed = $started->diffInSeconds(now(), true);

            if ($ride->dispatch_attempts >= max(1, ShuttleSettings::int('shuttle_dispatch_max_attempts')) || $elapsed >= $timeoutSec) {
                $this->markNoDrivers($ride);

                return $ride->fresh();
            }

            $radius = $this->currentRadius($ride);
            $exclude = $ride->offers()
                ->where(fn ($q) => $q->where('status', RideOffer::CANCELLED)->orWhere('offered_at', '>=', $started))
                ->pluck('driver_profile_id')->all();

            $best = $this->eligibleDrivers((string) $ride->vehicle_type, (float) $ride->pickup_lat, (float) $ride->pickup_lng, $radius, $exclude, true)->first();
            if (! $best) {
                return $ride;
            }

            $this->offerTo($ride, $best['driver'], $best['eta_min'], $best['km']);

            return $ride->fresh();
        } catch (\Throwable $e) {
            Log::error('[RideDispatch] advance failed for ride '.$ride->id.': '.$e->getMessage());

            return $ride;
        } finally {
            $lock->release();
        }
    }

    public function currentRadius(Ride $ride): float
    {
        $base = max(0.5, ShuttleSettings::float('shuttle_dispatch_radius_km'));
        $max = max($base, ShuttleSettings::float('shuttle_dispatch_max_radius_km'));
        $timeoutSec = max(30, ShuttleSettings::int('shuttle_dispatch_timeout_minutes') * 60);
        $elapsed = ($ride->dispatch_started_at ?? $ride->requested_at ?? now())->diffInSeconds(now(), true);

        return round($base + ($max - $base) * min(1, $elapsed / $timeoutSec), 1);
    }

    /**
     * Eligible drivers ranked best-first.
     *
     * @param  array<int, int>  $excludeIds
     * @return \Illuminate\Support\Collection<int, array{driver: DriverProfile, km: float, eta_min: int, score: float}>
     */
    public function eligibleDrivers(string $vehicleType, float $lat, float $lng, float $radiusKm, array $excludeIds = [], bool $roadEta = false): \Illuminate\Support\Collection
    {
        $stale = max(1, ShuttleSettings::int('shuttle_driver_stale_minutes'));
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / max(1.0, 111.0 * cos(deg2rad($lat)));

        $ranked = DriverProfile::query()
            ->with(['user', 'documents'])
            ->where('status', 'active')
            ->where('availability', DriverProfile::AVAILABLE)
            ->whereIn('vehicle_type', self::compatibleVehicleTypes($vehicleType))
            ->whereNotNull('current_lat')->whereNotNull('current_lng')
            ->where('location_updated_at', '>=', now()->subMinutes($stale))
            ->whereBetween('current_lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('current_lng', [$lng - $dLng, $lng + $dLng])
            ->when($excludeIds, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->whereDoesntHave('rides', fn ($q) => $q->whereIn('status', Ride::DRIVER_ACTIVE_STATUSES))
            ->whereDoesntHave('offers', fn ($q) => $q->where('status', RideOffer::PENDING)->where('expires_at', '>', now()))
            ->get()
            ->filter(fn (DriverProfile $d) => $d->complianceBlocker() === null)
            ->map(fn (DriverProfile $d) => ['driver' => $d, 'km' => RoutingService::haversineKm($lat, $lng, (float) $d->current_lat, (float) $d->current_lng)])
            ->filter(fn ($c) => $c['km'] <= $radiusKm)
            ->sortBy('km')
            ->values();

        // Road ETA for the closest few only (OSRM politeness), straight-line estimate for the rest.
        return $ranked->map(function ($c, $i) use ($lat, $lng, $roadEta) {
            $route = ($roadEta && $i < 3)
                ? $this->routing->route((float) $c['driver']->current_lat, (float) $c['driver']->current_lng, $lat, $lng, 3)
                : $this->routing->fallback((float) $c['driver']->current_lat, (float) $c['driver']->current_lng, $lat, $lng);
            $c['eta_min'] = (int) $route['duration_min'];
            $c['km'] = round((float) $route['distance_km'], 2);
            $c['score'] = $c['eta_min'] + (1 - $c['driver']->reliability()) * 5;

            return $c;
        })->sortBy('score')->values();
    }

    protected function offerTo(Ride $ride, DriverProfile $driver, int $etaMin, float $km): RideOffer
    {
        $timeout = max(5, ShuttleSettings::int('shuttle_offer_timeout_seconds'));
        $offer = RideOffer::query()->create([
            'ride_id' => $ride->id,
            'driver_profile_id' => $driver->id,
            'status' => RideOffer::PENDING,
            'attempt_no' => $ride->dispatch_attempts + 1,
            'distance_km' => $km,
            'eta_minutes' => $etaMin,
            'offered_at' => now(),
            'expires_at' => now()->addSeconds($timeout),
        ]);
        $ride->forceFill(['dispatch_attempts' => $ride->dispatch_attempts + 1])->save();

        if ($driver->user) {
            $this->notifier->push($driver->user, 'New ride offer', "Pickup {$etaMin} min away at {$ride->pickup_address}. Accept within {$timeout}s.", ['type' => 'ride', 'data' => ['route' => 'driver_offer', 'params' => ['ride_id' => $ride->id], 'url' => '/driver']]);
        }

        return $offer;
    }

    protected function expireOffers(Ride $ride): void
    {
        $ride->offers()->where('status', RideOffer::PENDING)->where('expires_at', '<=', now())->get()
            ->each(function (RideOffer $o) {
                $o->update(['status' => RideOffer::EXPIRED, 'responded_at' => now()]);
                DriverProfile::query()->whereKey($o->driver_profile_id)->increment('offers_missed_count');
            });
    }

    protected function markNoDrivers(Ride $ride): void
    {
        $ride->offers()->where('status', RideOffer::PENDING)->update(['status' => RideOffer::CANCELLED, 'responded_at' => now()]);
        $ride->forceFill(['dispatch_state' => Ride::DISPATCH_NO_DRIVERS])->save();
        $this->logEvent($ride, 'no_drivers', null, null);

        if ($ride->patient?->user) {
            $this->notifier->push($ride->patient->user, 'No drivers available', 'We could not find a driver nearby right now. You can try again or cancel at no charge.', ['type' => 'ride', 'data' => ['route' => 'ride', 'params' => ['ride_id' => $ride->id], 'url' => '/rides/'.$ride->id.'/track']]);
        }
        $this->alertAdmins('No driver found for '.$ride->ride_ref, "No eligible {$ride->vehicle_type} driver accepted within the search window. Pickup: {$ride->pickup_address}.", $ride);
    }

    public function retryDispatch(Ride $ride): Ride
    {
        if ($ride->status !== Ride::STATUS_REQUESTED || $ride->driver_profile_id) {
            throw new RideActionException('This ride is no longer waiting for a driver.', 409);
        }
        $ride->forceFill(['dispatch_state' => Ride::DISPATCH_SEARCHING, 'dispatch_started_at' => now(), 'dispatch_attempts' => 0])->save();
        $this->logEvent($ride, 'dispatch_retry', null, null);

        return $this->advance($ride);
    }

    /** Runs from Tick: progress every searching ride, promote due scheduled rides. */
    public function tickAll(): int
    {
        $this->promoteScheduled();

        $n = 0;
        Ride::query()->where('status', Ride::STATUS_REQUESTED)->whereNull('driver_profile_id')
            ->where('dispatch_state', Ride::DISPATCH_SEARCHING)->orderBy('id')->limit(100)->get()
            ->each(function (Ride $r) use (&$n) {
                $this->advance($r);
                $n++;
            });

        // Orphaned pending offers (ride no longer searching).
        RideOffer::query()->where('status', RideOffer::PENDING)->where('expires_at', '<=', now()->subMinutes(5))
            ->update(['status' => RideOffer::EXPIRED, 'responded_at' => now()]);

        return $n;
    }

    /** Scheduled (queue) rides whose pickup is within the lead time start searching if scheduled auto-assign is on. */
    public function promoteScheduled(): int
    {
        if (! (bool) Setting::get('ride_auto_assign_scheduled', true)) {
            return 0;
        }

        $lead = max(0, ShuttleSettings::int('shuttle_scheduled_dispatch_lead_minutes'));

        return Ride::query()
            ->where('status', Ride::STATUS_REQUESTED)->whereNull('driver_profile_id')
            ->where('dispatch_state', Ride::DISPATCH_QUEUE)
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [now()->subHours(2), now()->addMinutes($lead)])
            ->update(['dispatch_state' => Ride::DISPATCH_SEARCHING, 'dispatch_started_at' => now(), 'dispatch_attempts' => 0]);
    }

    // ------------------------------------------------------------------
    // Driver acceptance
    // ------------------------------------------------------------------

    public function acceptOffer(RideOffer $offer, DriverProfile $driver): Ride
    {
        if ($offer->driver_profile_id !== $driver->id) {
            throw new RideActionException('This offer is not yours.', 403);
        }

        $lock = Cache::lock("ride:{$offer->ride_id}:dispatch", 10);
        if (! $lock->block(5)) {
            throw new RideActionException('Please try again.', 409);
        }

        try {
            $offer->refresh();
            $ride = $offer->ride()->firstOrFail();

            if ($offer->status !== RideOffer::PENDING || ! $offer->expires_at || $offer->expires_at->isPast()) {
                if ($offer->status === RideOffer::PENDING) {
                    $offer->update(['status' => RideOffer::EXPIRED, 'responded_at' => now()]);
                    $driver->increment('offers_missed_count');
                }
                throw new RideActionException('This offer has expired.', 409);
            }
            if ($ride->status !== Ride::STATUS_REQUESTED || $ride->driver_profile_id) {
                $offer->update(['status' => RideOffer::CANCELLED, 'responded_at' => now()]);
                throw new RideActionException('This ride is no longer available.', 409);
            }
            $this->assertDriverCanTake($driver, $ride);

            $offer->update(['status' => RideOffer::ACCEPTED, 'responded_at' => now()]);
            $driver->increment('offers_accepted_count');

            return $this->assignDriver($ride, $driver, 'driver');
        } finally {
            $lock->release();
        }
    }

    public function declineOffer(RideOffer $offer, DriverProfile $driver): void
    {
        if ($offer->driver_profile_id !== $driver->id) {
            throw new RideActionException('This offer is not yours.', 403);
        }
        if ($offer->status === RideOffer::PENDING) {
            $offer->update(['status' => RideOffer::DECLINED, 'responded_at' => now()]);
            $driver->increment('offers_declined_count');
        }
        $this->advance($offer->ride);
    }

    /** Open-queue claim (auto-assign off for that context, or no driver was found). */
    public function claim(Ride $ride, DriverProfile $driver): Ride
    {
        $lock = Cache::lock("ride:{$ride->id}:dispatch", 10);
        if (! $lock->block(5)) {
            throw new RideActionException('Please try again.', 409);
        }

        try {
            $ride->refresh();
            if ($ride->status !== Ride::STATUS_REQUESTED || $ride->driver_profile_id) {
                throw new RideActionException('This ride has already been accepted.', 409);
            }

            $mine = $ride->offers()->where('driver_profile_id', $driver->id)->where('status', RideOffer::PENDING)->where('expires_at', '>', now())->first();
            if ($mine) {
                $lock->release();

                return $this->acceptOffer($mine, $driver);
            }

            if ($ride->offers()->where('status', RideOffer::PENDING)->where('expires_at', '>', now())->exists()) {
                throw new RideActionException('This ride is currently offered to another driver.', 409);
            }
            $this->assertDriverCanTake($driver, $ride);

            RideOffer::query()->create([
                'ride_id' => $ride->id, 'driver_profile_id' => $driver->id, 'status' => RideOffer::ACCEPTED,
                'attempt_no' => $ride->dispatch_attempts + 1, 'offered_at' => now(), 'expires_at' => now(), 'responded_at' => now(),
            ]);
            $driver->increment('offers_accepted_count');

            return $this->assignDriver($ride, $driver, 'driver');
        } finally {
            optional($lock)->release();
        }
    }

    protected function assertDriverCanTake(DriverProfile $driver, Ride $ride): void
    {
        if ($driver->status !== 'active') {
            throw new RideActionException('Your driver account is not active yet.', 403);
        }
        if ($blocker = $driver->complianceBlocker()) {
            throw new RideActionException("You can't take rides until your documents are up to date: {$blocker}.", 403);
        }
        if (! in_array($driver->vehicle_type, self::compatibleVehicleTypes((string) $ride->vehicle_type), true)) {
            throw new RideActionException('Your vehicle type can\'t serve this ride ('.str_replace('_', ' ', (string) $ride->vehicle_type).').', 422);
        }
        if (Ride::query()->where('driver_profile_id', $driver->id)->whereIn('status', Ride::DRIVER_ACTIVE_STATUSES)->where('id', '!=', $ride->id)->exists()) {
            throw new RideActionException('Finish your current trip first.', 409);
        }
    }

    public function assignDriver(Ride $ride, DriverProfile $driver, string $actor = 'admin'): Ride
    {
        $from = $ride->status;

        DB::transaction(function () use ($ride, $driver) {
            $ride->update([
                'driver_profile_id' => $driver->id,
                'status' => Ride::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'dispatch_state' => Ride::DISPATCH_ASSIGNED,
            ]);
            $ride->offers()->where('status', RideOffer::PENDING)->where('driver_profile_id', '!=', $driver->id)
                ->update(['status' => RideOffer::CANCELLED, 'responded_at' => now()]);
            $driver->update(['availability' => DriverProfile::BUSY]);
        });

        $this->logEvent($ride, Ride::STATUS_ACCEPTED, $driver->current_lat, $driver->current_lng);
        $this->fireStatus($ride, $from, Ride::STATUS_ACCEPTED, $actor);

        $ride->loadMissing('patient.user');
        $driver->loadMissing('user');
        if ($ride->patient?->user) {
            $this->notifier->push($ride->patient->user, 'Driver assigned', ($driver->user->name ?? 'Your driver')." is on the way in a {$driver->vehicle_color} {$driver->vehicle_make} {$driver->vehicle_model} ({$driver->vehicle_plate_no}).", ['type' => 'ride', 'data' => ['route' => 'ride', 'params' => ['ride_id' => $ride->id], 'url' => '/rides/'.$ride->id.'/track']]);
        }
        if ($driver->user) {
            $this->notifier->push($driver->user, 'Ride assigned', 'Pick up '.$this->firstName($ride->patient?->user?->name)." at {$ride->pickup_address}.", ['type' => 'ride', 'data' => ['route' => 'driver_ride', 'params' => ['ride_id' => $ride->id], 'url' => '/driver']]);
        }

        // Prime the driver->pickup ETA.
        $this->locations->driverEta($ride->fresh(['driver']), true);

        return $ride->fresh();
    }

    /** Admin manual (re)assignment. A previously assigned driver is released without a reliability penalty. */
    public function reassignByAdmin(Ride $ride, DriverProfile $driver): Ride
    {
        if (! in_array($ride->status, [Ride::STATUS_REQUESTED, Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED], true)) {
            throw new RideActionException('Only rides that have not started can be reassigned.', 409);
        }
        if ($ride->driver_profile_id === $driver->id) {
            return $ride;
        }
        if (Ride::query()->where('driver_profile_id', $driver->id)->whereIn('status', Ride::DRIVER_ACTIVE_STATUSES)->exists()) {
            throw new RideActionException('That driver is already on another trip.', 409);
        }

        $old = $ride->driver;
        if ($old) {
            $ride->offers()->where('driver_profile_id', $old->id)->whereIn('status', [RideOffer::ACCEPTED, RideOffer::PENDING])
                ->update(['status' => RideOffer::CANCELLED, 'responded_at' => now()]);
            $old->update(['availability' => DriverProfile::AVAILABLE]);
            if ($old->user) {
                $this->notifier->push($old->user, 'Trip reassigned', "Trip {$ride->ride_ref} was reassigned by Prompt Aid operations.", ['type' => 'ride', 'data' => ['url' => '/driver']]);
            }
            $this->logEvent($ride, 'reassigned', null, null, 'from '.($old->user?->name ?? '#'.$old->id));
            $ride->forceFill(['driver_profile_id' => null, 'status' => Ride::STATUS_REQUESTED, 'driver_arrived_at' => null, 'simulation' => null])->save();
        }

        RideOffer::query()->create([
            'ride_id' => $ride->id, 'driver_profile_id' => $driver->id, 'status' => RideOffer::ACCEPTED,
            'attempt_no' => $ride->dispatch_attempts + 1, 'offered_at' => now(), 'expires_at' => now(), 'responded_at' => now(),
        ]);

        return $this->assignDriver($ride->fresh(), $driver, 'admin');
    }

    // ------------------------------------------------------------------
    // Status transitions
    // ------------------------------------------------------------------

    protected const TRANSITIONS = [
        Ride::STATUS_ACCEPTED => [Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED],
        Ride::STATUS_DRIVER_ENROUTE => [Ride::STATUS_ARRIVED],
        Ride::STATUS_ARRIVED => [Ride::STATUS_IN_PROGRESS],
        Ride::STATUS_IN_PROGRESS => [Ride::STATUS_COMPLETED],
    ];

    /**
     * Driver advances the trip. $pin is required for in_progress when the
     * trip-PIN mode applies to this channel (ShuttleSettings::pinRequired).
     */
    public function driverUpdateStatus(Ride $ride, DriverProfile $driver, string $status, ?float $lat = null, ?float $lng = null, ?string $pin = null, bool $fromApi = false): Ride
    {
        if ($ride->driver_profile_id !== $driver->id) {
            throw new RideActionException('This is not your ride.', 403);
        }
        if ($status === $ride->status) {
            return $ride;
        }
        if (! in_array($status, self::TRANSITIONS[$ride->status] ?? [], true)) {
            throw new RideActionException('You can\'t change this trip from '.str_replace('_', ' ', $ride->status).' to '.str_replace('_', ' ', $status).'.', 409);
        }
        if ($status === Ride::STATUS_IN_PROGRESS && ShuttleSettings::pinRequired($fromApi)) {
            if (! $pin || ! hash_equals((string) $ride->trip_pin, trim($pin))) {
                $this->logEvent($ride, 'pin_failed', $lat, $lng);
                throw new RideActionException('That trip PIN is incorrect. Ask the patient for the 4-digit PIN shown in their app.', 422);
            }
        }

        return $this->updateStatus($ride, $status, $lat, $lng, 'driver');
    }

    /**
     * Apply a status (no transition validation — callers validate). Kept
     * public with the original signature for existing callers.
     */
    public function updateStatus(Ride $ride, string $status, ?float $lat = null, ?float $lng = null, string $actor = 'system'): Ride
    {
        if ($status === Ride::STATUS_CANCELLED) {
            return $this->finishCancel($ride, $actor === 'driver' ? 'admin' : $actor, null, 0.0, null);
        }

        $from = $ride->status;
        $updates = ['status' => $status];
        if ($status === Ride::STATUS_ARRIVED) {
            $updates['driver_arrived_at'] = now();
            $updates['eta_minutes'] = 0;
        }
        if ($status === Ride::STATUS_IN_PROGRESS) {
            $updates['started_at'] = now();
            $updates['driver_arrived_at'] = $ride->driver_arrived_at ?? now();
        }
        $ride->update($updates);

        if ($status === Ride::STATUS_COMPLETED) {
            return $this->complete($ride, $from, $actor, $lat, $lng);
        }

        $this->logEvent($ride, $status, $lat, $lng);
        $this->fireStatus($ride, $from, $status, $actor);

        $messages = [
            Ride::STATUS_DRIVER_ENROUTE => ['Driver on the way', 'Your driver is heading to your pickup point.'],
            Ride::STATUS_ARRIVED => ['Your driver has arrived', 'Your shuttle is waiting at '.$ride->pickup_address.'. Share your trip PIN '.$ride->trip_pin.' with the driver to start.'],
            Ride::STATUS_IN_PROGRESS => ['Trip started', 'You are on your way to '.$ride->dropoff_address.'.'],
        ];
        if (isset($messages[$status])) {
            $this->notifyPatient($ride, ...$messages[$status]);
        }

        if (in_array($status, [Ride::STATUS_IN_PROGRESS], true)) {
            $this->locations->driverEta($ride->fresh(['driver']), true);
        }

        return $ride->fresh();
    }

    protected function complete(Ride $ride, string $from, string $actor, ?float $lat, ?float $lng): Ride
    {
        $ride->forceFill(['completed_at' => now()])->save();
        $breakdown = $this->pricing->finalFare($ride->fresh());
        $final = (float) ($breakdown['final']['total'] ?? $ride->quoted_fare ?? $ride->fare_estimate ?? 0);

        $ride->forceFill([
            'fare_final' => $final,
            'fare_breakdown' => $breakdown,
            'eta_minutes' => 0,
            'simulation' => $ride->isSimulated() ? array_merge((array) $ride->simulation, ['active' => false]) : $ride->simulation,
        ])->save();

        $ride->driver?->update(['availability' => DriverProfile::AVAILABLE]);

        if (ShuttleSettings::bool('shuttle_charge_fare_on_completion') && $final > 0 && ! $ride->charges()->where('type', RideCharge::TYPE_FARE)->exists()) {
            $this->charges->charge($ride, RideCharge::TYPE_FARE, $final, 'Trip fare');
        }

        $this->logEvent($ride, Ride::STATUS_COMPLETED, $lat, $lng);
        $this->fireStatus($ride, $from, Ride::STATUS_COMPLETED, $actor);
        $this->safeEvent(fn () => event(new RideCompleted($ride->fresh())));
        $this->notifyPatient($ride, 'Trip completed', 'You have arrived. Your fare was R '.number_format($final, 2).'. Thanks for riding with Prompt Aid.');

        return $ride->fresh();
    }

    // ------------------------------------------------------------------
    // Cancellation
    // ------------------------------------------------------------------

    /** Driver ETA used for the cancellation-fee rule (null when not driving to pickup). */
    public function etaForPolicy(Ride $ride): ?int
    {
        if (! in_array($ride->status, [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE], true)) {
            return null;
        }

        return $this->locations->driverEta($ride)['minutes'] ?? null;
    }

    /** @return array{allowed: bool, fee: float, reason: string, free_until: string|null} */
    public function cancelPreview(Ride $ride): array
    {
        return $this->pricing->cancellationPolicy($ride, $this->etaForPolicy($ride));
    }

    /**
     * @param  float|null  $expectedFee  the fee the patient saw/confirmed; if it no longer matches, nothing is cancelled (409)
     */
    public function cancelByPatient(Ride $ride, ?string $reason, ?float $expectedFee = null): Ride
    {
        $policy = $this->cancelPreview($ride);
        if (! $policy['allowed']) {
            throw new RideActionException($policy['reason'], 409);
        }
        if ($expectedFee !== null && abs($policy['fee'] - $expectedFee) > 0.009) {
            throw new RideActionException('The cancellation fee is now R '.number_format($policy['fee'], 2).' ('.$policy['reason'].') Please confirm again.', 409);
        }

        return $this->finishCancel($ride, 'patient', $reason, $policy['fee'], RideCharge::TYPE_CANCELLATION);
    }

    /** Driver cancels an accepted trip: no patient fee, counts against the driver, ride re-dispatched. */
    public function cancelByDriver(Ride $ride, DriverProfile $driver, ?string $reason): Ride
    {
        if ($ride->driver_profile_id !== $driver->id) {
            throw new RideActionException('This is not your ride.', 403);
        }
        if (! in_array($ride->status, [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED], true)) {
            throw new RideActionException('This trip can no longer be cancelled by the driver.', 409);
        }

        $from = $ride->status;
        DB::transaction(function () use ($ride, $driver) {
            $ride->offers()->where('driver_profile_id', $driver->id)->whereIn('status', [RideOffer::ACCEPTED, RideOffer::PENDING])
                ->update(['status' => RideOffer::CANCELLED, 'responded_at' => now()]);
            if (! $ride->offers()->where('driver_profile_id', $driver->id)->where('status', RideOffer::CANCELLED)->exists()) {
                RideOffer::query()->create(['ride_id' => $ride->id, 'driver_profile_id' => $driver->id, 'status' => RideOffer::CANCELLED, 'offered_at' => now(), 'expires_at' => now(), 'responded_at' => now()]);
            }
            $driver->increment('rides_cancelled_count');
            $driver->update(['availability' => DriverProfile::AVAILABLE]);
            $ride->update([
                'driver_profile_id' => null,
                'status' => Ride::STATUS_REQUESTED,
                'accepted_at' => null,
                'driver_arrived_at' => null,
                'dispatch_state' => Ride::DISPATCH_SEARCHING,
                'dispatch_started_at' => now(),
                'dispatch_attempts' => 0,
                'simulation' => null,
            ]);
        });

        $this->logEvent($ride, 'driver_cancelled', $driver->current_lat, $driver->current_lng, $reason);
        $this->fireStatus($ride, $from, Ride::STATUS_REQUESTED, 'driver');
        $this->notifyPatient($ride, 'Finding you a new driver', 'Your driver had to cancel. We are matching you with the next nearest driver at no extra cost.');

        return $this->advance($ride->fresh());
    }

    /** Driver marks a no-show after waiting the configured minutes at pickup. */
    public function markNoShow(Ride $ride, DriverProfile $driver): Ride
    {
        if ($ride->driver_profile_id !== $driver->id) {
            throw new RideActionException('This is not your ride.', 403);
        }
        if ($ride->status !== Ride::STATUS_ARRIVED || ! $ride->driver_arrived_at) {
            throw new RideActionException('Mark yourself as arrived at the pickup first.', 409);
        }
        $wait = max(0, ShuttleSettings::int('shuttle_no_show_wait_minutes'));
        $waited = $ride->driver_arrived_at->diffInSeconds(now(), true);
        if ($waited < $wait * 60) {
            $left = (int) ceil(($wait * 60 - $waited) / 60);
            throw new RideActionException("Please wait {$left} more min before marking a no-show.", 409);
        }

        return $this->finishCancel($ride, 'no_show', 'Patient did not show up (waited '.(int) floor($waited / 60).' min)', $this->pricing->noShowFee($ride), RideCharge::TYPE_NO_SHOW);
    }

    public function cancelByAdmin(Ride $ride, ?string $reason, float $fee = 0.0): Ride
    {
        if (in_array($ride->status, [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED], true)) {
            throw new RideActionException('This ride has already ended.', 409);
        }

        return $this->finishCancel($ride, 'admin', $reason, $fee, $fee > 0 ? RideCharge::TYPE_CANCELLATION : null);
    }

    protected function finishCancel(Ride $ride, string $by, ?string $reason, float $fee, ?string $chargeType): Ride
    {
        if ($ride->status === Ride::STATUS_CANCELLED) {
            return $ride;
        }

        $from = $ride->status;
        $driver = $ride->driver;

        $ride->update([
            'status' => Ride::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancel_reason' => $reason ? mb_substr($reason, 0, 1000) : $ride->cancel_reason,
            'cancellation_fee' => $fee > 0 ? $fee : null,
            'cancellation_fee_status' => $fee > 0 ? RideCharge::STATUS_PENDING : null,
            'eta_minutes' => null,
            'simulation' => $ride->isSimulated() ? array_merge((array) $ride->simulation, ['active' => false]) : $ride->simulation,
        ]);
        $ride->offers()->where('status', RideOffer::PENDING)->update(['status' => RideOffer::CANCELLED, 'responded_at' => now()]);

        if ($driver && $driver->availability === DriverProfile::BUSY) {
            $driver->update(['availability' => DriverProfile::AVAILABLE]);
        }

        if ($fee > 0 && $chargeType) {
            $charge = $this->charges->charge($ride, $chargeType, $fee, $reason);
            $ride->forceFill(['cancellation_fee_status' => $charge?->status ?? RideCharge::STATUS_FAILED])->save();
        }

        $this->logEvent($ride, Ride::STATUS_CANCELLED, null, null, trim($by.($reason ? ': '.$reason : '')));
        $this->fireStatus($ride, $from, Ride::STATUS_CANCELLED, $by === 'no_show' ? 'driver' : $by);
        $this->safeEvent(fn () => event(new RideCancelled($ride->fresh(), $by, $fee)));

        $feeText = $fee > 0 ? ' A fee of R '.number_format($fee, 2).' applies.' : '';
        $this->notifyPatient($ride, $by === 'no_show' ? 'Marked as no-show' : 'Ride cancelled', ($by === 'no_show' ? 'Your driver waited at the pickup point but could not find you.' : 'Your shuttle ride has been cancelled.').$feeText);
        if ($driver?->user && $by !== 'no_show') {
            $this->notifier->push($driver->user, 'Ride cancelled', "Trip {$ride->ride_ref} was cancelled".($by === 'patient' ? ' by the patient.' : '.'), ['type' => 'ride', 'data' => ['route' => 'driver_ride', 'params' => ['ride_id' => $ride->id], 'url' => '/driver']]);
        }

        return $ride->fresh();
    }

    // ------------------------------------------------------------------
    // Ratings & safety
    // ------------------------------------------------------------------

    public function rateByPatient(Ride $ride, int $rating, ?string $comment): RideReview
    {
        if ($ride->status !== Ride::STATUS_COMPLETED) {
            throw new RideActionException('You can rate a trip once it is completed.', 409);
        }
        $review = RideReview::query()->updateOrCreate(['ride_id' => $ride->id], ['rating' => $rating, 'comment' => $comment]);

        if ($ride->driver_profile_id) {
            $stats = RideReview::query()->whereHas('ride', fn ($q) => $q->where('driver_profile_id', $ride->driver_profile_id))
                ->selectRaw('COUNT(*) as c, AVG(rating) as a')->first();
            DriverProfile::query()->whereKey($ride->driver_profile_id)->update(['rating_avg' => round((float) $stats->a, 2), 'rating_count' => (int) $stats->c]);
        }

        return $review;
    }

    public function rateByDriver(Ride $ride, DriverProfile $driver, int $rating, ?string $comment): Ride
    {
        if ($ride->driver_profile_id !== $driver->id) {
            throw new RideActionException('This is not your ride.', 403);
        }
        if ($ride->status !== Ride::STATUS_COMPLETED) {
            throw new RideActionException('You can rate a trip once it is completed.', 409);
        }
        $ride->update(['patient_rating' => $rating, 'patient_rating_comment' => $comment]);

        return $ride;
    }

    /** SOS from the patient tracking page: logged on the ride timeline + in-app alert to every super admin. */
    public function sos(Ride $ride, User $by, ?float $lat, ?float $lng): void
    {
        $this->logEvent($ride, 'sos', $lat ?? ($ride->driver?->current_lat), $lng ?? ($ride->driver?->current_lng), 'Raised by '.$by->name);
        $where = $lat !== null ? sprintf(' Location: %.5f, %.5f.', $lat, $lng) : '';
        $this->alertAdmins('SOS on ride '.$ride->ride_ref, "{$by->name} pressed SOS (status: ".str_replace('_', ' ', $ride->status).').'.$where.' Driver: '.($ride->driver?->user?->name ?? 'none').'.', $ride);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    public function logEvent(Ride $ride, string $status, $lat, $lng, ?string $note = null): void
    {
        try {
            RideStatusEvent::query()->create([
                'ride_id' => $ride->id,
                'status' => mb_substr($note ? $status.' | '.$note : $status, 0, 255),
                'lat' => $lat,
                'lng' => $lng,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[RideDispatch] event log failed: '.$e->getMessage());
        }
    }

    protected function fireStatus(Ride $ride, ?string $from, string $to, string $actor): void
    {
        $this->safeEvent(fn () => event(new RideStatusChanged($ride, $from, $to, $actor)));
    }

    protected function safeEvent(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('[RideDispatch] listener failed: '.$e->getMessage());
        }
    }

    protected function notifyPatient(Ride $ride, string $title, string $body): void
    {
        $user = $ride->patient?->user;
        if ($user) {
            $this->notifier->push($user, $title, $body, ['type' => 'ride', 'data' => ['route' => 'ride', 'params' => ['ride_id' => $ride->id], 'url' => '/rides/'.$ride->id.'/track']]);
        }
    }

    protected function alertAdmins(string $title, string $body, Ride $ride): void
    {
        $notifier = app(InAppNotifier::class);
        User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->pluck('id')
            ->each(fn ($id) => $notifier->send((int) $id, 'ride', $title, $body, ['url' => '/staff/rides/'.$ride->id]));
    }

    protected function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first !== '' ? $first : 'the patient';
    }
}
