<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideOffer;
use App\Services\Rides\PricingService;
use App\Services\Rides\RideActionException;
use App\Services\Rides\RideDispatchService;
use App\Services\Rides\RideLocationService;
use App\Services\Rides\RideSimulator;
use App\Services\Rides\RideStatusPresenter;
use App\Support\ShuttleSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Web driver console (/driver, role:driver). Mobile-first page that posts
 * browser geolocation, receives sequential ride offers with a countdown,
 * and runs the trip (arrived -> PIN -> start -> complete, cancel, no-show).
 * Every ride action checks the signed-in driver owns the offer/ride.
 */
class DriverConsoleController extends Controller
{
    public function __construct(
        protected RideDispatchService $dispatch,
        protected RideLocationService $locations,
        protected RideStatusPresenter $presenter,
        protected RideSimulator $simulator,
        protected PricingService $pricing,
    ) {}

    public function index(Request $request)
    {
        $driver = $this->driver($request);

        return view('driver.console', [
            'driver' => $driver->load('user'),
            'cancelReasons' => ShuttleSettings::json('shuttle_cancel_reasons_driver'),
            'pinRequired' => ShuttleSettings::pinRequired(false),
        ]);
    }

    public function state(Request $request)
    {
        $driver = $this->driver($request);

        // Lazy dispatch progression (no cron needed), at most every 4 s platform-wide from driver polls.
        if (Cache::add('driver-console:tick', 1, 4)) {
            $this->dispatch->tickAll();
        }

        $ride = $this->locations->activeRideFor($driver);
        if ($ride && $ride->isSimulated()) {
            $this->simulator->tick($ride);
            $ride = $ride->fresh();
        }
        $driver->refresh()->load('user');

        return response()->json([
            'driver' => $this->driverJson($driver),
            'offer' => $ride ? null : $this->offerJson($driver),
            'ride' => $ride ? $this->rideJson($ride, $driver, $request->query('rk'), $request->query('tk')) : null,
            'queue' => ($ride || $driver->availability !== DriverProfile::AVAILABLE) ? [] : $this->queueJson($driver),
            'last_completed' => $ride ? null : $this->lastCompletedJson($driver),
            'earnings' => $this->earnings($driver),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function online(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate(['online' => ['required', 'boolean']]);

        if ($data['online']) {
            if ($driver->status !== 'active') {
                return response()->json(['message' => 'Your driver account is awaiting approval. You can go online once an admin approves it.'], 403);
            }
            if ($blocker = $driver->complianceBlocker()) {
                return response()->json(['message' => "You can't go online until your documents are up to date: {$blocker}."], 403);
            }
            $driver->update(['availability' => $this->locations->activeRideFor($driver) ? DriverProfile::BUSY : DriverProfile::AVAILABLE]);
        } else {
            if ($this->locations->activeRideFor($driver)) {
                return response()->json(['message' => 'Finish or cancel your current trip before going offline.'], 409);
            }
            $driver->update(['availability' => DriverProfile::OFFLINE]);
        }

        return response()->json(['availability' => $driver->availability]);
    }

    public function location(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Ignore very poor fixes (wifi/cell guesses) for the live car.
        if (isset($data['accuracy']) && $data['accuracy'] > 300) {
            return response()->json(['accepted' => false, 'reason' => 'low_accuracy']);
        }

        // While the TEST simulator is driving this driver, ignore real pings.
        $ride = $this->locations->activeRideFor($driver);
        if ($ride && $ride->isSimulated()) {
            return response()->json(['accepted' => false, 'reason' => 'simulated']);
        }

        $result = $this->locations->record(
            $driver, (float) $data['lat'], (float) $data['lng'],
            isset($data['heading']) ? (float) $data['heading'] : null,
            isset($data['speed']) ? (float) $data['speed'] * 3.6 : null,
        );

        return response()->json($result);
    }

    public function acceptOffer(Request $request, RideOffer $offer)
    {
        return $this->attempt(fn () => $this->dispatch->acceptOffer($offer, $this->driver($request)), 'Trip accepted. Head to the pickup point.');
    }

    public function declineOffer(Request $request, RideOffer $offer)
    {
        return $this->attempt(fn () => $this->dispatch->declineOffer($offer, $this->driver($request)), 'Offer declined.');
    }

    public function claim(Request $request, Ride $ride)
    {
        return $this->attempt(fn () => $this->dispatch->claim($ride, $this->driver($request)), 'Trip accepted. Head to the pickup point.');
    }

    public function status(Request $request, Ride $ride)
    {
        $data = $request->validate([
            'status' => ['required', 'in:driver_enroute,arrived,in_progress,completed'],
            'pin' => ['nullable', 'string', 'max:8'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return $this->attempt(fn () => $this->dispatch->driverUpdateStatus(
            $ride, $this->driver($request), $data['status'],
            isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null,
            $data['pin'] ?? null, false,
        ), match ($data['status']) {
            'arrived' => 'Arrival sent to the patient.',
            'in_progress' => 'Trip started.',
            'completed' => 'Trip completed.',
            default => 'Updated.',
        });
    }

    public function cancel(Request $request, Ride $ride)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->dispatch->cancelByDriver($ride, $this->driver($request), $data['reason']), 'Trip cancelled. It has been passed to another driver.');
    }

    public function noShow(Request $request, Ride $ride)
    {
        return $this->attempt(fn () => $this->dispatch->markNoShow($ride, $this->driver($request)), 'Marked as no-show.');
    }

    public function rate(Request $request, Ride $ride)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->dispatch->rateByDriver($ride, $this->driver($request), (int) $data['rating'], $data['comment'] ?? null), 'Thanks for rating the patient.');
    }

    // ------------------------------------------------------------------

    protected function driver(Request $request): DriverProfile
    {
        $driver = $request->user()?->driverProfile;
        abort_unless($driver, 403, 'No driver profile on this account.');

        return $driver;
    }

    protected function attempt(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (RideActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => $ok]);
    }

    protected function driverJson(DriverProfile $d): array
    {
        return [
            'name' => $d->user?->name,
            'availability' => $d->availability,
            'online' => $d->availability !== DriverProfile::OFFLINE,
            'account_status' => $d->status,
            'compliance_blocker' => $d->complianceBlocker(),
            'vehicle' => trim(implode(' ', array_filter([$d->vehicle_color, $d->vehicle_make, $d->vehicle_model]))),
            'plate' => $d->vehicle_plate_no,
            'vehicle_type' => $d->vehicle_type,
            'rating' => $d->rating_count ? round((float) $d->rating_avg, 1) : null,
            'reliability' => $d->reliability(),
            'lat' => $d->current_lat !== null ? (float) $d->current_lat : null,
            'lng' => $d->current_lng !== null ? (float) $d->current_lng : null,
            'heading' => $d->current_heading !== null ? (float) $d->current_heading : null,
            'location_age_s' => $d->location_updated_at ? (int) $d->location_updated_at->diffInSeconds(now(), true) : null,
        ];
    }

    protected function offerJson(DriverProfile $driver): ?array
    {
        $offer = RideOffer::query()->with('ride.patient.user')
            ->where('driver_profile_id', $driver->id)->where('status', RideOffer::PENDING)->where('expires_at', '>', now())
            ->latest('offered_at')->first();
        if (! $offer || ! $offer->ride || $offer->ride->status !== Ride::STATUS_REQUESTED) {
            return null;
        }
        $r = $offer->ride;

        return [
            'id' => $offer->id,
            'ride_id' => $r->id,
            'seconds_left' => $offer->secondsLeft(),
            'timeout_s' => max(5, ShuttleSettings::int('shuttle_offer_timeout_seconds')),
            'patient_first_name' => $this->presenter->firstName($r->patient?->user?->name),
            'vehicle_type' => $r->vehicle_type,
            'pickup_address' => $r->pickup_address,
            'dropoff_address' => $r->dropoff_address,
            'pickup' => ['lat' => (float) $r->pickup_lat, 'lng' => (float) $r->pickup_lng],
            'dropoff' => ['lat' => (float) $r->dropoff_lat, 'lng' => (float) $r->dropoff_lng],
            'pickup_eta_min' => $offer->eta_minutes,
            'pickup_km' => $offer->distance_km !== null ? (float) $offer->distance_km : null,
            'trip_km' => $r->route_distance_km !== null ? (float) $r->route_distance_km : (float) $r->distance_km,
            'trip_min' => $r->route_duration_min ?? $r->eta_minutes,
            'fare' => (float) ($r->quoted_fare ?? $r->fare_estimate),
            'surge' => (float) ($r->surge_multiplier ?: 1) > 1,
        ];
    }

    protected function rideJson(Ride $ride, DriverProfile $driver, ?string $rk, ?string $tk): array
    {
        $ride->loadMissing('patient.user');
        $base = $this->presenter->forPatient($ride, $rk, $tk);
        $noShowAfter = max(0, ShuttleSettings::int('shuttle_no_show_wait_minutes')) * 60;
        $waitedS = $ride->driver_arrived_at ? (int) $ride->driver_arrived_at->diffInSeconds(now(), true) : 0;

        return [
            'id' => $ride->id,
            'ride_ref' => $ride->ride_ref,
            'status' => $ride->status,
            'status_label' => $base['status_label'],
            'patient_first_name' => $this->presenter->firstName($ride->patient?->user?->name),
            'patient_phone' => in_array($ride->status, [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED], true) ? $ride->patient?->user?->phone : null,
            'rider' => \App\Services\Dispatch\RiderProfile::forRide($ride, true),
            'vehicle_type' => $ride->vehicle_type,
            'pickup' => $base['pickup'],
            'dropoff' => $base['dropoff'],
            'eta' => $base['eta'],
            'route' => $base['route'],
            'trip_route' => $base['trip_route'],
            'fare' => (float) ($ride->quoted_fare ?? $ride->fare_estimate),
            'pin_required' => ShuttleSettings::pinRequired(false),
            'waiting' => $ride->status === Ride::STATUS_ARRIVED ? [
                'waited_s' => $waitedS,
                'free_wait_s' => max(0, ShuttleSettings::int('shuttle_free_wait_minutes')) * 60,
                'no_show_after_s' => $noShowAfter,
                'no_show_allowed' => $waitedS >= $noShowAfter,
            ] : null,
            'simulated' => $ride->isSimulated(),
            'wait_and_return' => (bool) $ride->wait_and_return,
        ];
    }

    protected function queueJson(DriverProfile $driver): array
    {
        $types = array_keys(array_filter([
            'sedan' => in_array($driver->vehicle_type, RideDispatchService::compatibleVehicleTypes('sedan'), true),
            'suv' => in_array($driver->vehicle_type, RideDispatchService::compatibleVehicleTypes('suv'), true),
            'van' => in_array($driver->vehicle_type, RideDispatchService::compatibleVehicleTypes('van'), true),
            'wheelchair_accessible' => $driver->vehicle_type === 'wheelchair_accessible',
            'stretcher' => $driver->vehicle_type === 'stretcher',
        ]));

        return Ride::query()
            ->where('status', Ride::STATUS_REQUESTED)->whereNull('driver_profile_id')
            ->where(fn ($q) => $q->whereNull('dispatch_state')->orWhereIn('dispatch_state', [Ride::DISPATCH_QUEUE, Ride::DISPATCH_NO_DRIVERS]))
            ->whereIn('vehicle_type', $types)
            ->latest('requested_at')->limit(40)->get()
            // Never show stale / long-over requests.
            ->filter(fn (Ride $r) => $r->isActive())->take(20)->values()
            ->map(fn (Ride $r) => [
                'id' => $r->id,
                'ride_ref' => $r->ride_ref,
                'vehicle_type' => $r->vehicle_type,
                'pickup_address' => $r->pickup_address,
                'dropoff_address' => $r->dropoff_address,
                'fare' => (float) ($r->quoted_fare ?? $r->fare_estimate),
                'trip_km' => (float) ($r->route_distance_km ?? $r->distance_km),
                'away_km' => $driver->current_lat !== null ? round(\App\Services\Geo\RoutingService::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $r->pickup_lat, (float) $r->pickup_lng), 1) : null,
                'scheduled_for' => $r->scheduled_for?->toIso8601String(),
            ])->all();
    }

    protected function lastCompletedJson(DriverProfile $driver): ?array
    {
        $r = Ride::query()->where('driver_profile_id', $driver->id)
            ->whereIn('status', [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED])
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->latest('updated_at')->first();
        if (! $r) {
            return null;
        }

        return [
            'id' => $r->id,
            'ride_ref' => $r->ride_ref,
            'status' => $r->status,
            'cancelled_by' => $r->cancelled_by,
            'fare' => $r->fare_final !== null ? (float) $r->fare_final : null,
            'breakdown' => is_array($r->fare_breakdown) ? ($r->fare_breakdown['final'] ?? null) : null,
            'rated' => $r->patient_rating !== null,
        ];
    }

    protected function earnings(DriverProfile $driver): array
    {
        $local = $this->pricing->localNow();
        $from = $local->copy()->startOfDay()->utc();
        $rides = Ride::query()->where('driver_profile_id', $driver->id)->where('status', Ride::STATUS_COMPLETED)
            ->where('completed_at', '>=', $from)->get(['fare_final', 'fare_estimate']);

        return [
            'today_total' => round((float) $rides->sum(fn ($r) => (float) ($r->fare_final ?? $r->fare_estimate)), 2),
            'today_trips' => $rides->count(),
        ];
    }
}
