<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'subtotal' => (float) $this->subtotal,
            'delivery_fee' => (float) $this->delivery_fee,
            'total' => (float) $this->total,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'delivery_address' => $this->delivery_address,
            'pharmacy' => new PharmacyResource($this->whenLoaded('pharmacy')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'product_name' => $i->product_name, 'qty' => $i->qty,
                'unit_price' => (float) $i->unit_price, 'amount' => (float) $i->amount,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
