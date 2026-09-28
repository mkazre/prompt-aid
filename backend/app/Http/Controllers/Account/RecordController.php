<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use Illuminate\Http\Request;

class RecordController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        $encounters = Encounter::query()
            ->whereHas('appointment', fn ($q) => $q->where('patient_profile_id', $patient->id))
            ->with(['appointment.doctor.user', 'prescription.items'])
            ->latest()
            ->limit(5)
            ->get();

        // Built from real appointments and lab results rather than a
        // dedicated activity-log model, since none exists for patients.
        $timeline = collect();

        foreach ($patient->appointments()->with('doctor.user')->get() as $appt) {
            $timeline->push([
                'date' => $appt->date,
                'type' => $appt->isVideo() ? 'Video consultation' : 'Consultation',
                'detail' => $appt->doctor->user->name.' — '.str($appt->status)->headline(),
            ]);
        }

        foreach ($patient->labRequests()->with('results')->get() as $labRequest) {
            foreach ($labRequest->results as $result) {
                $timeline->push([
                    'date' => $result->created_at,
                    'type' => 'Result',
                    'detail' => $result->label.' released',
                ]);
            }
        }

        $timeline = $timeline->sortByDesc('date')->take(15)->values();

        return view('account.record', compact('encounters', 'timeline', 'patient'));
    }
}
