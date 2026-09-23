<?php

namespace App\Services\Rides;

use App\Models\PatientProfile;
use App\Models\Ride;
use App\Models\RideSeries;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Recurring shuttle schedules ("every Mon/Wed/Fri at 09:00 until 15 Dec")
 * and return legs for a single ride. A RideSeries is the pattern; actual
 * Ride rows are materialised ahead of time by `php artisan rides:materialize-series`
 * (recommended as a daily cron job) so the driver-facing dispatch board
 * only ever deals with real, bookable rides — never the abstract pattern.
 */
class RideSeriesService
{
    public function __construct(protected RideDispatchService $dispatch) {}

    /**
     * @param  array{days: array<int, int>, time: string, until: string}  $pattern  days = ISO weekday ints (0=Sun..6=Sat)
     * @param  array{address: string, lat: float, lng: float}  $pickup
     * @param  array{address: string, lat: float, lng: float}  $dropoff
     */
    public function create(PatientProfile $patient, array $pattern, array $pickup, array $dropoff, string $vehicleType = 'sedan'): RideSeries
    {
        return RideSeries::query()->create([
            'patient_profile_id' => $patient->id,
            'pattern' => $pattern,
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'vehicle_type' => $vehicleType,
            'active' => true,
        ]);
    }

    public function pause(RideSeries $series): void
    {
        $series->update(['active' => false]);
    }

    public function resume(RideSeries $series): void
    {
        $series->update(['active' => true]);
    }

    /**
     * Create the return leg for an already-booked ride — same pickup/
     * dropoff reversed, linked via `return_of_ride_id` so both legs are
     * visible together everywhere a ride is shown.
     */
    public function requestReturnLeg(Ride $outbound, ?string $scheduledFor = null): Ride
    {
        // A scheduled-ahead return leg skips auto-assignment for the same
        // reason materialised series rides do — "closest driver right now"
        // means nothing for a pickup that isn't happening yet.
        $returnLeg = $this->dispatch->requestRide(
            patient: $outbound->patient,
            pickupAddress: $outbound->dropoff_address,
            pickupLat: (float) $outbound->dropoff_lat,
            pickupLng: (float) $outbound->dropoff_lng,
            dropoffAddress: $outbound->pickup_address,
            dropoffLat: (float) $outbound->pickup_lat,
            dropoffLng: (float) $outbound->pickup_lng,
            appointmentId: $outbound->appointment_id,
            vehicleType: $outbound->vehicle_type,
            autoAssign: ! $scheduledFor,
        );

        $returnLeg->update([
            'return_of_ride_id' => $outbound->id,
            'is_return' => true,
            'scheduled_for' => $scheduledFor,
        ]);

        return $returnLeg->fresh();
    }

    /**
     * Generate real Ride rows for every active series' occurrences falling
     * in the next $daysAhead days that don't already have one (idempotent —
     * safe to run daily). Returns the rides created.
     *
     * @return Collection<int, Ride>
     */
    public function materializeUpcoming(int $daysAhead = 7): Collection
    {
        $created = collect();

        RideSeries::query()->where('active', true)->with('patient')->chunk(50, function ($seriesChunk) use (&$created, $daysAhead) {
            foreach ($seriesChunk as $series) {
                $created = $created->merge($this->materializeSeries($series, $daysAhead));
            }
        });

        return $created;
    }

    /**
     * @return Collection<int, Ride>
     */
    protected function materializeSeries(RideSeries $series, int $daysAhead): Collection
    {
        $pattern = $series->pattern ?? [];
        $days = $pattern['days'] ?? [];
        $time = $pattern['time'] ?? '09:00';
        $until = isset($pattern['until']) ? Carbon::parse($pattern['until'])->endOfDay() : null;

        if (empty($days)) {
            return collect();
        }

        $created = collect();

        for ($i = 0; $i <= $daysAhead; $i++) {
            $date = today()->addDays($i);

            if (! in_array($date->dayOfWeek, $days, true)) {
                continue;
            }

            if ($until && $date->gt($until)) {
                continue;
            }

            $scheduledFor = Carbon::parse("{$date->toDateString()} {$time}");

            $exists = Ride::query()->where('ride_series_id', $series->id)
                ->whereDate('scheduled_for', $date->toDateString())
                ->exists();

            if ($exists) {
                continue;
            }

            $pickup = $series->pickup;
            $dropoff = $series->dropoff;

            $ride = $this->dispatch->requestRide(
                patient: $series->patient,
                pickupAddress: $pickup['address'] ?? '',
                pickupLat: (float) ($pickup['lat'] ?? 0),
                pickupLng: (float) ($pickup['lng'] ?? 0),
                dropoffAddress: $dropoff['address'] ?? '',
                dropoffLat: (float) ($dropoff['lat'] ?? 0),
                dropoffLng: (float) ($dropoff['lng'] ?? 0),
                vehicleType: $series->vehicle_type,
                autoAssign: false,
            );

            $ride->update([
                'ride_series_id' => $series->id,
                'scheduled_for' => $scheduledFor,
            ]);

            $created->push($ride);
        }

        return $created;
    }
}
