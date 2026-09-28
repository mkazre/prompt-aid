<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use Illuminate\Http\Request;

class PrescriptionsController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        $prescriptions = Prescription::query()
            ->whereHas('encounter.appointment', fn ($q) => $q->where('patient_profile_id', $patient->id))
            ->with(['items', 'encounter.appointment.doctor.user'])
            ->latest()
            ->get();

        $uploads = $patient->prescriptionUploads()->with('order')->latest()->get();

        return view('account.prescriptions', compact('prescriptions', 'uploads'));
    }
}
