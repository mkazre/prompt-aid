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
            'specialization' => $this->specialization,
            'qualification' => $this->qualification,
            'experience_years' => $this->experience_years,
            'bio' => $this->bio,
            'consultation_fee' => (float) $this->consultation_fee,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'status' => $this->status,
            'is_accepting_appointments' => (bool) $this->is_accepting_appointments,
            'availability_summary' => $this->availabilitySummary(),
            'clinics' => ClinicResource::collection($this->whenLoaded('clinics')),
        ];
    }
}
