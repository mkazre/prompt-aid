<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ThirdPartyProfile */
class ThirdPartyProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'service_type' => $this->service_type,
            'category' => $this->category,
            'license_no' => $this->license_no,
            'description' => $this->description,
            'logo' => $this->logo,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'status' => $this->status,
            'service_area' => $this->service_area,
            'accepts_walk_ins' => (bool) $this->accepts_walk_ins,
        ];
    }
}
