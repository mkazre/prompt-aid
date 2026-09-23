<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'patient_profile' => new PatientProfileResource($this->whenLoaded('patientProfile')),
            'doctor_profile' => new DoctorProfileResource($this->whenLoaded('doctorProfile')),
            'driver_profile' => new DriverProfileResource($this->whenLoaded('driverProfile')),
            'third_party_profile' => new ThirdPartyProfileResource($this->whenLoaded('thirdPartyProfile')),
        ];
    }
}
