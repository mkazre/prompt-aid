<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Encounter */
class EncounterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        return [
            'id' => $this->id,
            'vitals' => $this->vitals,
            'chief_complaint' => $this->chief_complaint,
            'diagnosis' => $this->diagnosis,
            'notes' => $this->notes,
            'follow_up_date' => $this->follow_up_date?->format('Y-m-d'),
            'prescription' => new PrescriptionResource($this->whenLoaded('prescription')),
        ];
    }
}
