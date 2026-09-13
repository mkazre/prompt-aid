<?php

namespace App\Http\Controllers;

use App\Models\DoctorProfile;
use App\Services\Booking\AppointmentBookingService;
use Illuminate\Http\Request;

class WebAppointmentController extends Controller
{
    public function store(Request $request, AppointmentBookingService $booking)
    {
        $data = $request->validate([
            'doctor_profile_id' => ['required', 'integer', 'exists:doctor_profiles,id'],
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required'],
            'visit_type' => ['required', 'in:clinic,telemed'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $doctor = DoctorProfile::findOrFail($data['doctor_profile_id']);

        try {
            $booking->book(
                $request->user()->patientProfile,
                $doctor,
                $data['clinic_id'],
                null,
                $data['date'],
                $data['start_time'],
                $data['visit_type'],
                $data['reason'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['start_time' => $e->getMessage()]);
        }

        return redirect()->route('dashboard')->with('success', 'Appointment booked! It is now pending confirmation.');
    }
}
