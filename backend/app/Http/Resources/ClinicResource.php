<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Clinic */
class ClinicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'cover_image' => $this->cover_image,
            'description' => $this->description,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'specialties' => $this->specialties,
            'working_hours' => $this->working_hours,
            'status' => $this->status,
            'doctors' => DoctorProfileResource::collection($this->whenLoaded('doctors')),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
        ];
    }
}
