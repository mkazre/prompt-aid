<?php

namespace App\Services\Rides;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideCharge;
use App\Support\ShuttleSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the polled live-trip JSON for the three audiences:
 *   - patient  (rides/{ride}/status): everything incl. PIN, fare breakdown, driver phone
 *   - share    (trip/{token}/status): map + driver first name/vehicle + ETA only
 *   - driver   (driver console): see DriverConsoleController
 *
 * Every poll also performs the lazy work that has no cron: dispatch
 * progression and (TEST) simulator advancement. Route polylines are only
 * included when the client's cached route key differs (rk/tk params).
 */
class RideStatusPresenter
{
    public const STATUS_LABELS = [
        'requested' => 'Finding your driver',
        'accepted' => 'Driver on the way',
        'driver_enroute' => 'Driver on the way',
        'arrived' => 'Driver has arrived',
        'in_progress' => 'On the way to drop-off',
        'completed' => 'Trip completed',
        'cancelled' => 'Cancelled',
    ];

    public function __construct(
        protected RideDispatchService $dispatch,
        protected RideLocationService $locations,
        protected RideSimulator $simulator,
    ) {}

    /** Lazy progression shared by every poll endpoint. */
    public function refresh(Ride $ride): Ride
    {
        if ($ride->status === Ride::STATUS_REQUESTED && $ride->dispatch_state === Ride::DISPATCH_SEARCHING) {
            $ride = $this->dispatch->advance($ride);
        }
        if ($ride->isSimulated()) {
            $this->simulator->tick($ride);
            $ride = $ride->fresh();
        }

        return $ride->loadMissing(['driver.user', 'patient.user']);
    }

    /** @return array<string, mixed> */
    public function forPatient(Ride $ride, ?string $routeKey = null, ?string $tripKey = null): array
    {
        $ride = $this->refresh($ride);
        $data = $this->base($ride, $routeKey, $tripKey, false);

        $data['trip_pin'] = $ride->isActive() ? $ride->trip_pin : null;
        $data['share_url'] = $ride->isActive() && $ride->share_token ? route('trip.share', $ride->share_token) : null;
        $data['fare'] = [
            'quoted' => $ride->quoted_fare !== null ? (float) $ride->quoted_fare : ($ride->fare_estimate !== null ? (float) $ride->fare_estimate : null),
            'final' => $ride->fare_final !== null ? (float) $ride->fare_final : null,
            'multiplier' => (float) ($ride->surge_multiplier ?: 1),
            'breakdown' => is_array($ride->fare_breakdown) ? $ride->fare_breakdown : null,
        ];
        $data['charges'] = $ride->charges()->orderBy('id')->get()->map(fn (RideCharge $c) => [
            'type' => $c->type, 'label' => $c->typeLabel(), 'amount' => (float) $c->amount, 'status' => $c->status, 'ref' => $c->gateway_ref,
        ])->all();
        if ($data['driver'] && $ride->hasDriverEnRoute()) {
            $data['driver']['phone'] = $ride->driver?->user?->phone;
        }
        $data['cancellation'] = $ride->status === Ride::STATUS_CANCELLED ? [
            'by' => $ride->cancelled_by,
            'reason' => $ride->cancel_reason,
            'fee' => $ride->cancellation_fee !== null ? (float) $ride->cancellation_fee : 0.0,
            'fee_status' => $ride->cancellation_fee_status,
            'at' => $ride->cancelled_at?->toIso8601String(),
        ] : null;
        $data['can_cancel'] = in_array($ride->status, [Ride::STATUS_REQUESTED, Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED], true);
        $data['rating'] = $ride->status === Ride::STATUS_COMPLETED ? ($ride->review ? ['rating' => (int) $ride->review->rating, 'comment' => $ride->review->comment] : false) : null;

        return $data;
    }

    /** Public share link: no medical / personal data beyond the driver's first name. */
    public function forShare(Ride $ride, ?string $routeKey = null, ?string $tripKey = null): array
    {
        $ride = $this->refresh($ride);
        $data = $this->base($ride, $routeKey, $tripKey, true);
        unset($data['timeline'], $data['dispatch']['attempts'], $data['ride_ref']);
        // Addresses can reveal a clinic/medical context: the share view shows map pins only.
        $data['pickup']['address'] = null;
        $data['dropoff']['address'] = null;
        $data['patient_first_name'] = $this->firstName($ride->patient?->user?->name);

        return $data;
    }

    /** @return array<string, mixed> */
    protected function base(Ride $ride, ?string $routeKey, ?string $tripKey, bool $public): array
    {
        $eta = $ride->hasDriverEnRoute() ? $this->locations->driverEta($ride) : null;

        // Active leg route (driver -> pickup, or driver -> drop-off) and the planned trip route.
        $active = $eta ? $eta['geometry'] : null;
        $trip = is_array($ride->route_geometry) && count($ride->route_geometry) > 1
            ? $ride->route_geometry
            : [[(float) $ride->pickup_lat, (float) $ride->pickup_lng], [(float) $ride->dropoff_lat, (float) $ride->dropoff_lng]];
        $activeKey = $active ? substr(md5(json_encode($active)), 0, 12) : null;
        $tripKeyNow = substr(md5(json_encode($trip)), 0, 12);

        $driver = null;
        $d = $ride->driver;
        if ($d && $ride->status !== Ride::STATUS_REQUESTED) {
            $driver = $this->driverCard($d, $public);
            if ($ride->hasDriverEnRoute()) {
                $driver += [
                    'lat' => $d->current_lat !== null ? (float) $d->current_lat : null,
                    'lng' => $d->current_lng !== null ? (float) $d->current_lng : null,
                    'heading' => $d->current_heading !== null ? (float) $d->current_heading : null,
                    'speed_kmh' => $d->current_speed_kmh !== null ? (float) $d->current_speed_kmh : null,
                    'location_age_s' => $d->location_updated_at ? (int) $d->location_updated_at->diffInSeconds(now(), true) : null,
                ];
            }
        }

        $timeoutAt = $ride->dispatch_started_at?->copy()->addMinutes(max(1, ShuttleSettings::int('shuttle_dispatch_timeout_minutes')));

        return [
            'id' => $public ? null : $ride->id,
            'ride_ref' => $ride->ride_ref,
            'status' => $ride->status,
            'status_label' => $this->statusLabel($ride),
            'is_active' => $ride->isActive(),
            'vehicle_type' => $ride->vehicle_type,
            'pickup' => ['lat' => (float) $ride->pickup_lat, 'lng' => (float) $ride->pickup_lng, 'address' => $ride->pickup_address],
            'dropoff' => ['lat' => (float) $ride->dropoff_lat, 'lng' => (float) $ride->dropoff_lng, 'address' => $ride->dropoff_address],
            'dispatch' => [
                'state' => $ride->status === Ride::STATUS_REQUESTED ? ($ride->dispatch_state ?? Ride::DISPATCH_QUEUE) : $ride->dispatch_state,
                'radius_km' => $ride->status === Ride::STATUS_REQUESTED && $ride->dispatch_state === Ride::DISPATCH_SEARCHING ? $this->dispatch->currentRadius($ride) : null,
                'attempts' => (int) $ride->dispatch_attempts,
                'searching_since' => $ride->dispatch_started_at?->toIso8601String(),
                'timeout_at' => $timeoutAt?->toIso8601String(),
                'scheduled_for' => $ride->scheduled_for?->toIso8601String(),
            ],
            'driver' => $driver,
            'eta' => $eta ? [
                'minutes' => $eta['minutes'],
                'distance_km' => $eta['distance_km'],
                'target' => $eta['target'],
                'source' => $eta['source'],
                'computed_at' => $eta['computed_at'],
            ] : null,
            'trip' => [
                'distance_km' => $ride->route_distance_km !== null ? (float) $ride->route_distance_km : ($ride->distance_km !== null ? (float) $ride->distance_km : null),
                'duration_min' => $ride->route_duration_min ?? $ride->eta_minutes,
            ],
            'route' => [
                'key' => $activeKey,
                'kind' => $eta['target'] ?? null,
                'points' => $activeKey && $activeKey !== $routeKey ? $active : null,
            ],
            'trip_route' => [
                'key' => $tripKeyNow,
                'points' => $tripKeyNow !== $tripKey ? $trip : null,
            ],
            'waiting' => $ride->status === Ride::STATUS_ARRIVED && $ride->driver_arrived_at ? [
                'arrived_at' => $ride->driver_arrived_at->toIso8601String(),
                'free_wait_min' => ShuttleSettings::int('shuttle_free_wait_minutes'),
                'rate_per_min' => ShuttleSettings::float('shuttle_wait_rate_per_minute'),
            ] : null,
            'timeline' => $this->timeline($ride),
            'simulated' => $ride->isSimulated(),
            'server_time' => now()->toIso8601String(),
            'poll_ms' => $ride->isActive() ? 3500 : 0,
        ];
    }

    /** @return array<string, mixed> */
    public function driverCard(DriverProfile $d, bool $public): array
    {
        $name = (string) ($d->user?->name ?? 'Driver');
        $avatar = $d->user?->avatar;
        if ($avatar && ! preg_match('#^https?://#', $avatar)) {
            $avatar = Storage::disk('public')->url($avatar);
        }

        return [
            'name' => $public ? $this->firstName($name) : $name,
            'first_name' => $this->firstName($name),
            'initials' => $d->initials(),
            'photo_url' => $public ? null : $avatar,
            'vehicle' => trim(implode(' ', array_filter([$d->vehicle_color, $d->vehicle_make, $d->vehicle_model]))),
            'vehicle_type' => $d->vehicle_type,
            'plate' => $d->vehicle_plate_no,
            'rating' => $d->rating_count ? round((float) $d->rating_avg, 1) : null,
            'rating_count' => (int) $d->rating_count,
        ];
    }

    public function statusLabel(Ride $ride): string
    {
        if ($ride->status === Ride::STATUS_REQUESTED) {
            return match ($ride->dispatch_state) {
                Ride::DISPATCH_NO_DRIVERS => 'No drivers available',
                Ride::DISPATCH_QUEUE => $ride->scheduled_for && $ride->scheduled_for->isFuture() ? 'Scheduled' : 'Waiting for a driver',
                default => 'Finding your driver',
            };
        }
        if ($ride->status === Ride::STATUS_CANCELLED && $ride->cancelled_by === 'no_show') {
            return 'No-show';
        }

        return self::STATUS_LABELS[$ride->status] ?? ucfirst(str_replace('_', ' ', $ride->status));
    }

    /** @return array<int, array{key: string, label: string, at: string|null, state: string}> */
    public function timeline(Ride $ride): array
    {
        $steps = [
            ['requested', 'Requested', $ride->requested_at],
            ['accepted', 'Driver assigned', $ride->accepted_at],
            ['arrived', 'Driver arrived', $ride->driver_arrived_at],
            ['in_progress', 'Trip started', $ride->started_at],
            ['completed', 'Arrived at drop-off', $ride->completed_at],
        ];
        $order = ['requested' => 0, 'accepted' => 1, 'driver_enroute' => 1, 'arrived' => 2, 'in_progress' => 3, 'completed' => 4, 'cancelled' => -1];
        $current = $order[$ride->status] ?? 0;

        $out = [];
        foreach ($steps as $i => [$key, $label, $at]) {
            $state = $at ? 'done' : 'todo';
            if ($ride->status !== Ride::STATUS_CANCELLED && $i === $current && $ride->status !== Ride::STATUS_COMPLETED) {
                $state = 'now';
            }
            $out[] = ['key' => $key, 'label' => $label, 'at' => $at?->toIso8601String(), 'state' => $state];
        }
        if ($ride->status === Ride::STATUS_CANCELLED) {
            $out[] = ['key' => 'cancelled', 'label' => $ride->cancelled_by === 'no_show' ? 'Marked no-show' : 'Cancelled', 'at' => $ride->cancelled_at?->toIso8601String(), 'state' => 'stop'];
        }

        return $out;
    }

    public function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first !== '' ? $first : 'Patient';
    }
}
