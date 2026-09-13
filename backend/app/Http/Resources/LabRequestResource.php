<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LabRequest */
class LabRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_ref' => $this->request_ref,
            'priority' => $this->priority,
            'clinical_notes' => $this->clinical_notes,
            'collection_address' => $this->collection_address,
            'collection_lat' => $this->collection_lat,
            'collection_lng' => $this->collection_lng,
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'sample_collected_at' => $this->sample_collected_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'doctor' => new DoctorProfileResource($this->whenLoaded('doctor')),
            'patient' => new PatientProfileResource($this->whenLoaded('patient')),
            'third_party' => $this->whenLoaded('thirdParty', fn () => $this->thirdParty ? [
                'id' => $this->thirdParty->id,
                'company_name' => $this->thirdParty->company_name,
                'service_type' => $this->thirdParty->service_type,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'test_name' => $i->test_name, 'sample_type' => $i->sample_type, 'notes' => $i->notes,
            ])),
            'results' => $this->whenLoaded('results', fn () => $this->results->map(fn ($r) => [
                'id' => $r->id, 'label' => $r->label, 'file_path' => $r->file_path, 'summary' => $r->summary,
                'uploaded_at' => $r->created_at?->toIso8601String(),
            ])),
        ];
    }
}
