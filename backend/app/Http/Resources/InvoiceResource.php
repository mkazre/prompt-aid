<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'status' => $this->status,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'clinic' => new ClinicResource($this->whenLoaded('clinic')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'qty' => $item->qty,
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) $item->amount,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'status' => $payment->status,
                'gateway_ref' => $payment->gateway_ref,
                'created_at' => $payment->created_at?->toIso8601String(),
            ])),
        ];
    }
}
