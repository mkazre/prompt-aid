<?php

namespace App\Services\Rides;

use App\Contracts\GeocodingInterface;
use App\Contracts\NotificationDispatcherInterface;
use App\Models\DriverProfile;
use App\Models\PatientProfile;
use App\Models\Ride;
use App\Models\RideRateCard;
use App\Models\RideStatusEvent;

class RideDispatchService
{
    public function __construct(
        protected GeocodingInterface $geo,
        protected NotificationDispatcherInterface $notifier,
    ) {}

    /**
     * Admin-configurable rate card per vehicle type (Filament: Ride Rate Cards).
     * Falls back to a sensible sedan-equivalent default if no card is configured.
     */
    public function rateCardFor(string $vehicleType): RideRateCard
    {
        return RideRateCard::query()->where('vehicle_type', $vehicleType)->where('is_active', true)->first()
            ?? new RideRateCard(['vehicle_type' => $vehicleType, 'base_fare' => 30, 'per_km_rate' => 12, 'per_minute_rate' => 0, 'minimum_fare' => 30]);
    }

    /**
     * Live fare quotes for every active vehicle type — powers the "choose your ride" UI.
     *
     * @return array<int, array{vehicle_type: string, distance_km: float, eta_minutes: int, fare: float}>
     */
    public function quotesFor(float $pickupLat, float $pickupLng, float $dropoffLat, float $dropoffLng): array
    {
        $distance = $this->geo->distanceKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        $eta = $this->geo->etaMinutes($distance);

        return RideRateCard::query()->where('is_active', true)->get()
            ->map(fn (RideRateCard $card) => [
                'vehicle_type' => $card->vehicle_type,
                'distance_km' => $distance,
                'eta_minutes' => $eta,
                'fare' => $card->estimate($distance, $eta),
            ])
            ->values()
            ->all();
    }

    public function estimateFare(float $distanceKm, string $vehicleType = 'sedan', int $etaMinutes = 0): float
    {
        return $this->rateCardFor($vehicleType)->estimate($distanceKm, $etaMinutes);
    }

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
    ): Ride {
        $distance = $this->geo->distanceKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        $eta = $this->geo->etaMinutes($distance);
        $fare = $this->estimateFare($distance, $vehicleType, $eta);

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
            'distance_km' => $distance,
            'eta_minutes' => $eta,
            'fare_estimate' => $fare,
        ]);

        $this->logEvent($ride, Ride::STATUS_REQUESTED, $pickupLat, $pickupLng);

        // Try to auto-assign the closest available driver with a matching
        // vehicle type — skipped for rides scheduled ahead of time (return
        // legs, materialised recurring series), since "closest available
        // driver right now" is meaningless for a ride days in the future.
        if ($autoAssign) {
            $driver = $this->findNearestAvailableDriver($pickupLat, $pickupLng, $vehicleType);

            if ($driver) {
                $this->assignDriver($ride, $driver);
            }
        }

        return $ride->fresh();
    }

    public function findNearestAvailableDriver(float $lat, float $lng, ?string $vehicleType = null): ?DriverProfile
    {
        return DriverProfile::query()
            ->where('availability', DriverProfile::AVAILABLE)
            ->where('status', 'active')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->when($vehicleType, fn ($q) => $q->where('vehicle_type', $vehicleType))
            ->get()
            ->sortBy(fn (DriverProfile $driver) => $this->geo->distanceKm($lat, $lng, (float) $driver->current_lat, (float) $driver->current_lng))
            ->first()
            // Fall back to any available driver if none match the requested vehicle type exactly.
            ?? ($vehicleType ? $this->findNearestAvailableDriver($lat, $lng, null) : null);
    }

    public function assignDriver(Ride $ride, DriverProfile $driver): Ride
    {
        $ride->update([
            'driver_profile_id' => $driver->id,
            'status' => Ride::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        $driver->update(['availability' => DriverProfile::BUSY]);

        $this->logEvent($ride, Ride::STATUS_ACCEPTED, $driver->current_lat, $driver->current_lng);

        $this->notifier->push($ride->patient->user, 'Driver assigned', "{$driver->user->name} is on the way to pick you up.");
        $this->notifier->push($driver->user, 'New ride assigned', "Pick up {$ride->patient->user->name} at {$ride->pickup_address}.");

        return $ride->fresh();
    }

    public function updateStatus(Ride $ride, string $status, ?float $lat = null, ?float $lng = null): Ride
    {
        $timestamps = match ($status) {
            Ride::STATUS_IN_PROGRESS => ['started_at' => now()],
            Ride::STATUS_COMPLETED => ['completed_at' => now(), 'fare_final' => $ride->fare_final ?? $ride->fare_estimate],
            default => [],
        };

        $ride->update(array_merge(['status' => $status], $timestamps));

        if ($status === Ride::STATUS_COMPLETED && $ride->driver) {
            $ride->driver->update(['availability' => DriverProfile::AVAILABLE]);
        }

        if ($status === Ride::STATUS_CANCELLED && $ride->driver) {
            $ride->driver->update(['availability' => DriverProfile::AVAILABLE]);
        }

        $this->logEvent($ride, $status, $lat, $lng);

        return $ride->fresh();
    }

    public function updateDriverLocation(DriverProfile $driver, float $lat, float $lng): void
    {
        $driver->update([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'location_updated_at' => now(),
        ]);

        $activeRide = Ride::query()
            ->where('driver_profile_id', $driver->id)
            ->whereNotIn('status', [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED])
            ->latest()
            ->first();

        if ($activeRide) {
            // Keep a live ETA-to-pickup/dropoff for the tracking map.
            $target = in_array($activeRide->status, [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE], true)
                ? ['lat' => $activeRide->pickup_lat, 'lng' => $activeRide->pickup_lng]
                : ['lat' => $activeRide->dropoff_lat, 'lng' => $activeRide->dropoff_lng];

            $etaDistance = $this->geo->distanceKm($lat, $lng, (float) $target['lat'], (float) $target['lng']);
            $activeRide->update(['eta_minutes' => $this->geo->etaMinutes($etaDistance)]);

            $this->logEvent($activeRide, $activeRide->status, $lat, $lng);
        }
    }

    protected function logEvent(Ride $ride, string $status, ?float $lat, ?float $lng): void
    {
        RideStatusEvent::query()->create([
            'ride_id' => $ride->id,
            'status' => $status,
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }
}
