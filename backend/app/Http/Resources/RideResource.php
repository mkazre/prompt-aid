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
            'patient' => new PatientProfileResource($this->whenLoaded('patient')),
            'driver' => new DriverProfileResource($this->whenLoaded('driver')),
            'status_events' => $this->whenLoaded('statusEvents', fn () => $this->statusEvents->map(fn ($e) => [
                'status' => $e->status,
                'lat' => $e->lat ? (float) $e->lat : null,
                'lng' => $e->lng ? (float) $e->lng : null,
                'at' => $e->created_at?->toIso8601String(),
            ])),
        ];
    }
}
