<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Appointment */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_ref' => $this->booking_ref,
            'date' => $this->date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'visit_type' => $this->visit_type,
            'status' => $this->status,
            'reason' => $this->reason,
            'cancel_reason' => $this->cancel_reason,
            'ride_requested' => (bool) $this->ride_requested,
            'patient' => new PatientProfileResource($this->whenLoaded('patient')),
            'doctor' => new DoctorProfileResource($this->whenLoaded('doctor')),
            'clinic' => new ClinicResource($this->whenLoaded('clinic')),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'encounter' => new EncounterResource($this->whenLoaded('encounter')),
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'ride' => new RideResource($this->whenLoaded('ride')),
        ];
    }
}
