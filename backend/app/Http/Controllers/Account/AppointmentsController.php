<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Booking\AppointmentBookingService;
use Illuminate\Http\Request;

class AppointmentsController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        return view('account.appointments', [
            'appointments' => $patient->appointments()
                ->with(['doctor.user', 'clinic', 'invoice'])
                ->orderByDesc('date')->orderByDesc('start_time')
                ->get(),
        ]);
    }

    /**
     * Server-side join: participant + window checked here, so the room URL
     * never appears in the page source.
     */
    public function video(Request $request, Appointment $appointment, \App\Services\Video\VideoRoomService $video)
    {
        abort_unless($video->isParticipant($appointment, $request->user()), 404);

        if (! $appointment->canJoinVideoNow()) {
            return redirect()->route('account.appointments')->with('error', 'The video room opens 10 minutes before your appointment and closes 30 minutes after it ends.');
        }

        // In-page call (signed JWT, no Jitsi login): patient → website call page, doctor → staff consult page.
        return redirect()->to($video->callUrlFor($appointment, $request->user()));
    }

    public function cancel(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        // Route-model binding alone doesn't scope to the logged-in patient.
        abort_unless($appointment->patient_profile_id === $request->user()->patientProfile?->id, 403);

        if (in_array($appointment->status, [Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            return back()->with('error', 'This appointment can no longer be cancelled.');
        }

        $booking->cancel($appointment, $request->input('reason'));

        return back()->with('success', 'Appointment cancelled.');
    }
}
