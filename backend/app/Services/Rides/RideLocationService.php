<?php

namespace App\Services\Rides;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideLocation;
use App\Services\Geo\RoutingService;
use App\Support\ShuttleSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Driver GPS pings (mobile app POST /api/driver/location and the web driver
 * console) and live ETA.
 *
 * Pings: validated, throttled (min interval, admin setting), implausible
 * jumps (> 250 km/h) rejected; driver position + heading/speed updated; a
 * ride_locations breadcrumb written while the driver has an active ride.
 *
 * ETA: road ETA driver -> pickup (before pickup) or -> drop-off (in
 * progress), recomputed through RoutingService at most once per
 * shuttle_eta_refresh_seconds per ride (cached), and counted down between
 * recomputes so it never looks frozen.
 */
class RideLocationService
{
    public function __construct(protected RoutingService $routing) {}

    public function activeRideFor(DriverProfile $driver): ?Ride
    {
        return Ride::query()
            ->where('driver_profile_id', $driver->id)
            ->whereIn('status', Ride::DRIVER_ACTIVE_STATUSES)
            ->latest('accepted_at')
            ->first();
    }

    /**
     * @return array{accepted: bool, reason: string|null, ride_id: int|null}
     */
    public function record(DriverProfile $driver, float $lat, float $lng, ?float $heading = null, ?float $speedKmh = null, bool $simulated = false): array
    {
        if (abs($lat) > 90 || abs($lng) > 180 || (abs($lat) < 0.0001 && abs($lng) < 0.0001)) {
            return ['accepted' => false, 'reason' => 'invalid_coordinates', 'ride_id' => null];
        }

        $prevLat = $driver->current_lat !== null ? (float) $driver->current_lat : null;
        $prevLng = $driver->current_lng !== null ? (float) $driver->current_lng : null;
        $prevAt = $driver->location_updated_at;

        if (! $simulated && $prevAt) {
            $dt = max(0.001, $prevAt->diffInMilliseconds(now(), true) / 1000);
            $minInterval = max(0, ShuttleSettings::float('shuttle_location_min_interval_seconds'));
            if ($dt < $minInterval) {
                return ['accepted' => false, 'reason' => 'throttled', 'ride_id' => null];
            }
            if ($prevLat !== null && $dt < 120) {
                $kmh = RoutingService::haversineKm($prevLat, $prevLng, $lat, $lng) / ($dt / 3600);
                if ($kmh > 250) {
                    return ['accepted' => false, 'reason' => 'implausible_jump', 'ride_id' => null];
                }
                $speedKmh ??= round($kmh, 1);
            }
        }

        if ($heading === null && $prevLat !== null && RoutingService::haversineKm($prevLat, $prevLng, $lat, $lng) > 0.005) {
            $heading = RoutingService::bearing($prevLat, $prevLng, $lat, $lng);
        }

        $driver->forceFill([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'current_heading' => $heading !== null ? round(fmod($heading + 360, 360), 1) : $driver->current_heading,
            'current_speed_kmh' => $speedKmh !== null ? round(max(0, $speedKmh), 1) : null,
            'location_updated_at' => now(),
        ])->save();

        $ride = $this->activeRideFor($driver);
        if ($ride) {
            RideLocation::query()->create([
                'ride_id' => $ride->id,
                'driver_profile_id' => $driver->id,
                'lat' => $lat,
                'lng' => $lng,
                'heading' => $driver->current_heading,
                'speed_kmh' => $driver->current_speed_kmh,
                'phase' => $ride->status === Ride::STATUS_IN_PROGRESS ? 'to_dropoff' : 'to_pickup',
                'recorded_at' => now(),
            ]);
        }

        return ['accepted' => true, 'reason' => null, 'ride_id' => $ride?->id];
    }

    /**
     * Live ETA for the ride's current leg, or null when it doesn't apply
     * (no driver / no driver position / not moving towards a target).
     *
     * @return array{minutes: int, distance_km: float, geometry: array, target: string, source: string, computed_at: string}|null
     */
    public function driverEta(Ride $ride, bool $force = false): ?array
    {
        $driver = $ride->driver;
        if (! $driver || $driver->current_lat === null || $driver->current_lng === null) {
            return null;
        }

        $target = match ($ride->status) {
            Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE => 'pickup',
            Ride::STATUS_IN_PROGRESS => 'dropoff',
            default => null,
        };
        if (! $target) {
            return null;
        }

        $key = "ride:{$ride->id}:eta:{$target}";
        $cached = $force ? null : Cache::get($key);

        if (! is_array($cached)) {
            [$tLat, $tLng] = $target === 'pickup'
                ? [(float) $ride->pickup_lat, (float) $ride->pickup_lng]
                : [(float) $ride->dropoff_lat, (float) $ride->dropoff_lng];

            $route = $this->routing->route((float) $driver->current_lat, (float) $driver->current_lng, $tLat, $tLng);
            $cached = [
                'minutes' => (int) $route['duration_min'],
                'distance_km' => (float) $route['distance_km'],
                'geometry' => $route['geometry'],
                'target' => $target,
                'source' => $route['source'],
                'computed_at' => now()->toIso8601String(),
                'computed_ts' => microtime(true),
            ];
            Cache::put($key, $cached, max(10, ShuttleSettings::int('shuttle_eta_refresh_seconds')));

            $ride->forceFill(['eta_minutes' => $cached['minutes'], 'eta_updated_at' => now()])->saveQuietly();
        }

        // Count down between recomputes.
        $elapsedMin = (microtime(true) - (float) ($cached['computed_ts'] ?? microtime(true))) / 60;
        $cached['minutes'] = max($cached['minutes'] > 0 ? 1 : 0, (int) ceil($cached['minutes'] - $elapsedMin));
        unset($cached['computed_ts']);

        return $cached;
    }

    /** @return array<int, array{0: float, 1: float, 2: float|null, 3: string|null, 4: string|null}> lat,lng,heading,phase,at */
    public function trail(Ride $ride, int $limit = 2000): array
    {
        return $ride->locations()->orderBy('recorded_at')->orderBy('id')->limit($limit)
            ->get(['lat', 'lng', 'heading', 'phase', 'recorded_at'])
            ->map(fn (RideLocation $l) => [round((float) $l->lat, 6), round((float) $l->lng, 6), $l->heading, $l->phase, $l->recorded_at?->toIso8601String()])
            ->all();
    }
}
