<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DoctorProfile */
class DoctorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'avatar' => $this->user?->avatar,
            'avatar_url' => \App\Support\Widgets\WidgetDataService::mediaUrl($this->user?->avatar),
            // Only present when the list endpoint was called with ?lat=&lng=.
            'distance_km' => $this->when($this->resource->getAttribute('distance_km') !== null, fn () => (float) $this->resource->getAttribute('distance_km')),
            'specialization' => $this->specialization,
            'qualification' => $this->qualification,
            'experience_years' => $this->experience_years,
            'bio' => $this->bio,
            'consultation_fee' => (float) $this->consultation_fee,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'status' => $this->status,
            'is_accepting_appointments' => (bool) $this->is_accepting_appointments,
            'offers_video' => (bool) $this->offers_video,
            // Patient-facing traffic light: green / orange / red.
            'live_status' => $this->liveStatus(),
            'live_status_label' => $this->liveStatusLabel(),
            'live_status_description' => $this->liveStatusDescription(),
            'operating_hours' => (object) $this->operatingHours(),
            'availability_summary' => $this->availabilitySummary(),
            'clinics' => ClinicResource::collection($this->whenLoaded('clinics')),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
        ];
    }
}
