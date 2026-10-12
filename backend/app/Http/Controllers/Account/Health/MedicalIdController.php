<?php

namespace App\Http\Controllers\Account\Health;

use App\Http\Controllers\Account\Health\Concerns\ResolvesPatient;
use App\Http\Controllers\Controller;
use App\Services\Health\PatientHealth;
use Illuminate\Http\Request;

class MedicalIdController extends Controller
{
    use ResolvesPatient;

    public function show(Request $request)
    {
        $this->requireFeature('medical_id');
        $patient = $this->patient($request);

        return view('account.health.medical-id', [
            'card' => PatientHealth::medicalId($patient),
            'patient' => $patient,
            'bloodGroups' => PatientHealth::BLOOD_GROUPS,
        ]);
    }

    public function update(Request $request)
    {
        $this->requireFeature('medical_id');
        $patient = $this->patient($request);
        $patient->update($request->validate(PatientHealth::medicalIdRules()));

        return redirect()->route('account.health.medical-id')->with('success', 'Medical ID updated.');
    }
}
