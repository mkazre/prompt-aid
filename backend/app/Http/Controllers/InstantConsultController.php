<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\Doctors\DoctorMatchService;
use App\Support\SiteLinks;
use Illuminate\Http\Request;

/**
 * "Get connected to a Doctor in 30min" (PromptAid Emergency Scale, orange).
 * The patient's request goes to every GP flagged green; the first to accept
 * is booked with them 30 minutes later (DoctorMatchService::claim()).
 */
class InstantConsultController extends Controller
{
    public function __construct(protected DoctorMatchService $match) {}

    public function show(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            // Bring the patient straight back here after logging in.
            $request->session()->put('url.intended', route('instant-consult'));
        }

        $patient = $user?->patientProfile;

        $current = $patient
            ? Appointment::query()
                ->where('patient_profile_id', $patient->id)
                ->where('requested_specialty', DoctorMatchService::INSTANT_GP_SPECIALTY)
                ->where('created_at', '>=', now()->subHours(4))
                ->whereIn('status', [Appointment::STATUS_MATCHING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_PENDING])
                ->with(['doctor.user', 'clinic'])
                ->latest()
                ->first()
            : null;

        return view('pages.instant-consult', [
            'isPatient' => (bool) $patient,
            'current' => $current,
            'greenGpCount' => $this->match->greenGps()->count(),
            'whatsappUrl' => SiteLinks::whatsappUrl(),
        ]);
    }

    public function store(Request $request)
    {
        $patient = $request->user()?->patientProfile;
        abort_unless($patient, 403, 'Only patient accounts can request a doctor.');

        $data = $request->validate([
            'mode' => ['required', 'in:video,in_person'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->match->greenGps()->isEmpty()) {
            return back()->withErrors(['mode' => 'No GP is available to see you within 30 minutes right now. Please book a doctor manually, or use Ask a Doctor on WhatsApp.']);
        }

        $this->match->requestInstantGp($patient, $data['mode'], $data['reason'] ?? null);

        return redirect()->route('instant-consult')->with('success', 'Your request has been sent to every available GP. The first GP to accept will be booked with you in 30 minutes.');
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        abort_unless($appointment->patient_profile_id === $request->user()?->patientProfile?->id, 403);

        if ($appointment->status === Appointment::STATUS_MATCHING) {
            $appointment->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => 'Cancelled by patient before a GP accepted.']);
        }

        return redirect()->route('instant-consult');
    }
}
