<?php

namespace App\Http\Controllers\Account\Health\Concerns;

use App\Models\PatientProfile;
use App\Support\HealthSettings;
use Illuminate\Http\Request;

trait ResolvesPatient
{
    protected function patient(Request $request): PatientProfile
    {
        $patient = $request->user()?->patientProfile;
        abort_unless($patient, 403, 'These pages are for patient accounts.');

        return $patient;
    }

    protected function requireFeature(string $feature): void
    {
        abort_unless(HealthSettings::feature($feature), 404);
    }
}
