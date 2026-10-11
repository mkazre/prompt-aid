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
            'service_id' => ['nullable', 'integer'],
        ]);

        $doctor = DoctorProfile::findOrFail($data['doctor_profile_id']);

        // Only this doctor's own active services can be attached.
        $serviceId = ! empty($data['service_id'])
            ? $doctor->services()->whereKey($data['service_id'])->where('is_active', true)->value('id')
            : null;

        $patient = $request->user()->patientProfile;
        if (! $patient) {
            return back()->withErrors(['start_time' => 'Appointments can only be booked from a patient account.']);
        }

        try {
            $booking->book(
                $patient,
                $doctor,
                $data['clinic_id'],
                $serviceId,
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
