<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pharmacy_id' => $this->pharmacy_id,
            'pharmacy_name' => $this->whenLoaded('pharmacy', fn () => $this->pharmacy->name),
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'description' => $this->description,
            'image' => $this->image,
            'price' => (float) $this->price,
            'stock' => $this->stock,
            'requires_prescription' => (bool) $this->requires_prescription,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
