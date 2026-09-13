<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Pharmacy */
class PharmacyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'description' => $this->description,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'delivery_fee' => (float) $this->delivery_fee,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'status' => $this->status,
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
