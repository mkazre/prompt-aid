<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\RideSeries */
class RideSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pattern' => $this->pattern,
            'pickup' => $this->pickup,
            'dropoff' => $this->dropoff,
            'vehicle_type' => $this->vehicle_type,
            'active' => (bool) $this->active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
