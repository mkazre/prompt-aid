<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DriverProfile */
class DriverProfileResource extends JsonResource
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
            'phone' => $this->user?->phone,
            'vehicle_make' => $this->vehicle_make,
            'vehicle_model' => $this->vehicle_model,
            'vehicle_color' => $this->vehicle_color,
            'vehicle_plate_no' => $this->vehicle_plate_no,
            'vehicle_type' => $this->vehicle_type,
            'vehicle_photo' => $this->vehicle_photo,
            'availability' => $this->availability,
            'current_lat' => $this->current_lat,
            'current_lng' => $this->current_lng,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'status' => $this->status,
        ];
    }
}
