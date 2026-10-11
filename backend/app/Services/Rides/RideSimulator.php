<?php

namespace App\Services\Rides;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Services\Geo\RoutingService;
use App\Support\ShuttleSettings;
use Illuminate\Support\Facades\Cache;

/**
 * TEST-ONLY trip simulator (super admin, Filament ride view). Moves the
 * assigned driver's position along the road route so the patient tracking
 * page shows a moving car without a real driver.
 *
 * It does nothing unless an admin explicitly started it on that ride
 * (rides.simulation.active = true). Advancement is lazy: tick() runs on
 * status polls (patient, share link, driver console, admin) at most every
 * 2 s per ride, computing the position from elapsed time x speed.
 * With "auto progress", it also marks arrived -> starts the trip after 15 s
 * (PIN bypassed, logged as simulator) -> completes at the drop-off.
 */
class RideSimulator
{
    public function __construct(
        protected RoutingService $routing,
        protected RideLocationService $locations,
        protected RideDispatchService $dispatch,
    ) {}

    public function start(Ride $ride, ?DriverProfile $driver, float $speedKmh, bool $autoProgress, int $startedBy): Ride
    {
        if (! $ride->isActive()) {
            throw new RideActionException('This ride has already ended.');
        }
        if (! $ride->driver_profile_id) {
            if (! $driver) {
                throw new RideActionException('Pick a driver to simulate.');
            }
            $ride = $this->dispatch->assignDriver($ride, $driver, 'simulator');
        }
        $ride->load('driver');
        $d = $ride->driver;

        // Start from the driver's real position if near the pickup, else ~3 km away (OSRM snaps to a road).
        $pLat = (float) $ride->pickup_lat;
        $pLng = (float) $ride->pickup_lng;
        if ($d->current_lat !== null && RoutingService::haversineKm((float) $d->current_lat, (float) $d->current_lng, $pLat, $pLng) <= 30) {
            [$sLat, $sLng] = [(float) $d->current_lat, (float) $d->current_lng];
        } else {
            [$sLat, $sLng] = [$pLat + 0.02, $pLng + 0.018];
        }

        $leg = $ride->status === Ride::STATUS_IN_PROGRESS ? 'to_dropoff' : ($ride->status === Ride::STATUS_ARRIVED ? 'wait' : 'to_pickup');

        $ride->forceFill(['simulation' => [
            'active' => true,
            'leg' => $leg,
            'leg_started_at' => microtime(true),
            'start' => [$sLat, $sLng],
            'speed_kmh' => max(5, min(200, $speedKmh ?: ShuttleSettings::float('shuttle_simulator_speed_kmh'))),
            'auto' => $autoProgress,
            'started_by' => $startedBy,
            'started_at' => now()->toIso8601String(),
        ]])->save();

        $this->locations->record($d, $sLat, $sLng, null, 0, true);
        $this->dispatch->logEvent($ride, 'simulation_started', $sLat, $sLng, 'TEST simulator');

        return $ride->fresh();
    }

    public function stop(Ride $ride): Ride
    {
        if ($ride->isSimulated()) {
            $ride->forceFill(['simulation' => array_merge((array) $ride->simulation, ['active' => false])])->save();
            $this->dispatch->logEvent($ride, 'simulation_stopped', null, null, 'TEST simulator');
        }

        return $ride->fresh();
    }

    /** Lazy advance; no-op unless the ride's simulation was explicitly started. */
    public function tick(Ride $ride): void
    {
        if (! $ride->isSimulated() || ! $ride->driver_profile_id || ! Cache::add("ride:{$ride->id}:simtick", 1, 2)) {
            return;
        }

        try {
            $this->advance($ride->fresh(['driver']));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[RideSimulator] '.$e->getMessage());
        }
    }

    protected function advance(Ride $ride): void
    {
        $sim = (array) $ride->simulation;
        if (empty($sim['active']) || ! $ride->isActive() || ! $ride->driver) {
            if (! $ride->isActive() && ! empty($sim['active'])) {
                $ride->forceFill(['simulation' => array_merge($sim, ['active' => false])])->save();
            }

            return;
        }

        $elapsed = max(0, microtime(true) - (float) ($sim['leg_started_at'] ?? microtime(true)));
        $km = ($elapsed / 3600) * (float) ($sim['speed_kmh'] ?? 45);
        $auto = ! empty($sim['auto']);

        switch ($sim['leg'] ?? 'to_pickup') {
            case 'to_pickup':
                if ($auto && $ride->status === Ride::STATUS_ACCEPTED) {
                    $ride = $this->dispatch->updateStatus($ride, Ride::STATUS_DRIVER_ENROUTE, null, null, 'simulator');
                }
                $route = $this->routing->route((float) $sim['start'][0], (float) $sim['start'][1], (float) $ride->pickup_lat, (float) $ride->pickup_lng);
                $p = RoutingService::pointAlong($route['geometry'], $km);
                $this->locations->record($ride->driver, $p['lat'], $p['lng'], $p['heading'], (float) $sim['speed_kmh'], true);
                if ($p['done'] || in_array($ride->status, [Ride::STATUS_ARRIVED, Ride::STATUS_IN_PROGRESS], true)) {
                    if ($auto && in_array($ride->status, [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE], true)) {
                        $this->dispatch->updateStatus($ride, Ride::STATUS_ARRIVED, $p['lat'], $p['lng'], 'simulator');
                    }
                    $this->setLeg($ride, $sim, 'wait');
                }
                break;

            case 'wait':
                if ($ride->status === Ride::STATUS_IN_PROGRESS) {
                    $this->setLeg($ride, $sim, 'to_dropoff');
                } elseif ($auto && $ride->status === Ride::STATUS_ARRIVED && $elapsed >= 15) {
                    $this->dispatch->logEvent($ride, 'pin_bypassed', null, null, 'TEST simulator started the trip');
                    $this->dispatch->updateStatus($ride, Ride::STATUS_IN_PROGRESS, null, null, 'simulator');
                    $this->setLeg($ride->fresh(), $sim, 'to_dropoff');
                }
                break;

            case 'to_dropoff':
                if ($ride->status !== Ride::STATUS_IN_PROGRESS) {
                    break;
                }
                $geometry = is_array($ride->route_geometry) && count($ride->route_geometry) > 1
                    ? $ride->route_geometry
                    : $this->routing->route((float) $ride->pickup_lat, (float) $ride->pickup_lng, (float) $ride->dropoff_lat, (float) $ride->dropoff_lng)['geometry'];
                $p = RoutingService::pointAlong($geometry, $km);
                $this->locations->record($ride->driver, $p['lat'], $p['lng'], $p['heading'], (float) $sim['speed_kmh'], true);
                if ($p['done']) {
                    if ($auto) {
                        $this->dispatch->updateStatus($ride, Ride::STATUS_COMPLETED, $p['lat'], $p['lng'], 'simulator');
                    }
                    $fresh = $ride->fresh();
                    $fresh->forceFill(['simulation' => array_merge((array) $fresh->simulation, ['active' => false, 'leg' => 'done'])])->save();
                }
                break;
        }
    }

    protected function setLeg(Ride $ride, array $sim, string $leg): void
    {
        $sim['leg'] = $leg;
        $sim['leg_started_at'] = microtime(true);
        $ride->forceFill(['simulation' => $sim])->save();
    }
}
