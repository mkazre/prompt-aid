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
