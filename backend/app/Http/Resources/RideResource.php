<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Ride */
class RideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        return [
            'id' => $this->id,
            'ride_ref' => $this->ride_ref,
            'vehicle_type' => $this->vehicle_type,
            'eta_minutes' => $this->eta_minutes,
            'pickup_address' => $this->pickup_address,
            'pickup_lat' => (float) $this->pickup_lat,
            'pickup_lng' => (float) $this->pickup_lng,
            'dropoff_address' => $this->dropoff_address,
            'dropoff_lat' => (float) $this->dropoff_lat,
            'dropoff_lng' => (float) $this->dropoff_lng,
            'status' => $this->status,
            // Lifecycle contract (additive): is_terminal = completed/cancelled
            // (incl. expired); is_active = not terminal AND not stale. Apps use
            // is_active to decide whether to open tracking.
            'is_active' => $this->resource->isActive(),
            'is_terminal' => $this->resource->isTerminal(),
            'is_expired' => $this->resource->isExpired(),
            'status_label' => app(\App\Services\Rides\RideStatusPresenter::class)->statusLabel($this->resource),
            'dispatch_state' => $this->dispatch_state,
            'radius_km' => $this->radius_km !== null ? (float) $this->radius_km : null,
            'pickup_notes' => $this->pickup_notes,
            // Trip PIN: only to the patient who owns the ride, only while it is live.
            'trip_pin' => $this->when($this->viewerIsPatient($request) && $this->resource->isActive(), $this->trip_pin),
            // Drivers: rider card (limited while only offered) + whether the app must collect the PIN.
            'rider' => $this->when($this->viewerDriverId($request) !== null, fn () => \App\Services\Dispatch\RiderProfile::forRide($this->resource, (int) $this->driver_profile_id === $this->viewerDriverId($request))),
            'pin_required' => $this->when($this->viewerDriverId($request) !== null, fn () => \App\Support\ShuttleSettings::pinRequired(true)),
            'priority' => $this->priority,
            'distance_km' => $this->distance_km ? (float) $this->distance_km : null,
            'fare_estimate' => $this->fare_estimate ? (float) $this->fare_estimate : null,
            'fare_final' => $this->fare_final ? (float) $this->fare_final : null,
            'cancel_reason' => $this->cancel_reason,
            'is_return' => (bool) $this->is_return,
            'return_of_ride_id' => $this->return_of_ride_id,
            'wait_and_return' => (bool) $this->wait_and_return,
            'ride_series_id' => $this->ride_series_id,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Drivers only get what they need (rider card rules) — never DOB,
            // allergies, chronic conditions or home address.
            'patient' => $this->viewerDriverId($request) !== null
                ? $this->whenLoaded('patient', fn () => $this->driverSafePatient($request))
                : new PatientProfileResource($this->whenLoaded('patient')),
            'driver' => new DriverProfileResource($this->whenLoaded('driver')),
            'status_events' => $this->whenLoaded('statusEvents', fn () => $this->statusEvents->map(fn ($e) => [
                'status' => $e->status,
                'lat' => $e->lat ? (float) $e->lat : null,
                'lng' => $e->lng ? (float) $e->lng : null,
                'at' => $e->created_at?->toIso8601String(),
            ])),
        ];
    }

    /** @return array<string, mixed> */
    protected function driverSafePatient(Request $request): array
    {
        $card = \App\Services\Dispatch\RiderProfile::forRide($this->resource, (int) $this->driver_profile_id === $this->viewerDriverId($request));
        $name = $card['full_name'] ?? $card['first_name'];

        return [
            'id' => $this->patient_profile_id,
            'name' => $name,
            'phone' => $card['phone'],
            'user' => ['name' => $name, 'phone' => $card['phone']],
        ];
    }

    protected function viewerIsPatient(Request $request): bool
    {
        $pid = $request->user()?->patientProfile?->id;

        return $pid !== null && (int) $this->patient_profile_id === (int) $pid;
    }

    protected function viewerDriverId(Request $request): ?int
    {
        $user = $request->user();

        return $user && $user->role === \App\Models\User::ROLE_DRIVER ? $user->driverProfile?->id : null;
    }
}
