<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Services\Booking\AppointmentBookingService;
use Illuminate\Http\Request;

/**
 * Doctor-side booking actions from the mobile calendar: view own slot
 * grid, book an appointment for one of their existing patients, and
 * reschedule one of their own appointments. Strictly scoped to the
 * authenticated doctor's profile, clinics and patients.
 */
class DoctorBookingController extends Controller
{
    public function __construct(protected AppointmentBookingService $booking) {}

    protected function doctor(Request $request): DoctorProfile
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403, 'No doctor profile on this account.');

        return $doctor;
    }

    /** @return array<int, int> */
    protected function clinicIds(DoctorProfile $doctor): array
    {
        return $doctor->clinics()->pluck('clinics.id')->map(fn ($id) => (int) $id)->all();
    }

    public function slots(Request $request)
    {
        $doctor = $this->doctor($request);

        $data = $request->validate([
            'clinic_id' => ['required', 'integer', 'in:'.implode(',', $this->clinicIds($doctor) ?: [0])],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_id' => ['nullable', 'integer'],
        ]);

        // Only ignore the doctor's own appointment when rescheduling.
        $ignore = null;
        if (! empty($data['appointment_id'])) {
            $ignore = Appointment::query()->where('doctor_profile_id', $doctor->id)->whereKey($data['appointment_id'])->value('id');
        }

        $schedule = $this->booking->slotSchedule($doctor, (int) $data['clinic_id'], $data['date'], $ignore);

        return response()->json($schedule + [
            'slots' => $this->booking->availableSlots($doctor, (int) $data['clinic_id'], $data['date'], $ignore)->values(),
        ]);
    }

    public function store(Request $request)
    {
        $doctor = $this->doctor($request);

        $data = $request->validate([
            'patient_profile_id' => ['required', 'integer'],
            'clinic_id' => ['required', 'integer', 'in:'.implode(',', $this->clinicIds($doctor) ?: [0])],
            'service_id' => ['nullable', 'integer'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'visit_type' => ['required', 'in:clinic,telemed,home'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], ['clinic_id.in' => 'Choose one of your own clinics.']);

        // "One of their patients": someone with at least one appointment with this doctor.
        $isOwnPatient = Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->where('patient_profile_id', $data['patient_profile_id'])
            ->exists();

        abort_unless($isOwnPatient, 422, 'You can only book for your own patients.');

        if (! empty($data['service_id'])) {
            abort_unless($doctor->services()->whereKey($data['service_id'])->where('is_active', true)->exists(), 422, 'Choose one of your own services.');
        }

        $patient = PatientProfile::with('user')->findOrFail($data['patient_profile_id']);

        try {
            $appointment = $this->booking->book(
                $patient,
                $doctor,
                (int) $data['clinic_id'],
                $data['service_id'] ?? null,
                $data['date'],
                $data['start_time'],
                $data['visit_type'],
                $data['reason'] ?? null,
                ['status' => Appointment::STATUS_CONFIRMED, 'by_doctor' => true],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new AppointmentResource($appointment->load(['patient.user', 'clinic', 'service']));
    }

    public function reschedule(Request $request, int $id)
    {
        $doctor = $this->doctor($request);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
        ]);

        $appointment = Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->findOrFail($id);

        try {
            $this->booking->reschedule($appointment, $data['date'], $data['start_time']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new AppointmentResource($appointment->fresh(['patient.user', 'clinic', 'service']));
    }
}
