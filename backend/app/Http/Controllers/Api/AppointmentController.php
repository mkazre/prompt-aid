<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\DoctorProfile;
use App\Services\Booking\AppointmentBookingService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(protected AppointmentBookingService $bookingService) {}

    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        $appointments = $patient->appointments()
            ->with(['doctor.user', 'clinic', 'service', 'invoice', 'ride'])
            ->orderByDesc('date')
            ->paginate(15);

        return AppointmentResource::collection($appointments);
    }

    public function show(Request $request, int $id)
    {
        $appointment = $request->user()->patientProfile->appointments()
            ->with(['doctor.user', 'clinic', 'service', 'encounter.prescription.items', 'invoice.items', 'invoice.payments', 'ride'])
            ->findOrFail($id);

        return new AppointmentResource($appointment);
    }

    public function availableSlots(Request $request, DoctorProfile $doctor)
    {
        $request->validate([
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $slots = $this->bookingService->availableSlots($doctor, $request->integer('clinic_id'), $request->string('date'));

        return response()->json(['slots' => $slots->values()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'doctor_profile_id' => ['required', 'integer', 'exists:doctor_profiles,id'],
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required'],
            'visit_type' => ['required', 'in:clinic,telemed,home'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $doctor = DoctorProfile::findOrFail($data['doctor_profile_id']);
        $patient = $request->user()->patientProfile;

        try {
            $appointment = $this->bookingService->book(
                $patient,
                $doctor,
                $data['clinic_id'],
                $data['service_id'] ?? null,
                $data['date'],
                $data['start_time'],
                $data['visit_type'],
                $data['reason'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new AppointmentResource($appointment->load(['doctor.user', 'clinic', 'service']));
    }

    public function cancel(Request $request, int $id)
    {
        $appointment = $request->user()->patientProfile->appointments()->findOrFail($id);

        $this->bookingService->cancel($appointment, $request->string('reason'));

        return new AppointmentResource($appointment->fresh());
    }
}
