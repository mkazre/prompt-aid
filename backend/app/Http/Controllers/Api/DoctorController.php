<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\PatientProfile;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Endpoints for the doctor role in the mobile app — a lightweight parallel
 * to the richer /staff Filament panel, covering just what the doctor's
 * dashboard/calendar/patients screens need on the go.
 */
class DoctorController extends Controller
{
    public function stats(Request $request)
    {
        $doctor = $request->user()->doctorProfile;

        $totalPatients = Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->distinct()
            ->count('patient_profile_id');

        $totalAppointments = Appointment::query()->where('doctor_profile_id', $doctor->id)->count();

        $weekStart = now()->startOfWeek();
        $weekly = collect(range(0, 6))->map(function (int $i) use ($doctor, $weekStart) {
            $day = $weekStart->clone()->addDays($i);

            return [
                'day' => $day->format('D'),
                'date' => $day->toDateString(),
                'count' => Appointment::query()
                    ->where('doctor_profile_id', $doctor->id)
                    ->whereDate('date', $day)
                    ->count(),
            ];
        });

        return response()->json([
            'total_patients' => $totalPatients,
            'total_appointments' => $totalAppointments,
            'weekly_appointments' => $weekly,
        ]);
    }

    public function appointments(Request $request)
    {
        $doctor = $request->user()->doctorProfile;

        $query = Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->with(['patient.user', 'clinic', 'service'])
            ->orderBy('start_time');

        if ($request->filled('date')) {
            $query->whereDate('date', Carbon::parse($request->string('date')));
        } else {
            $query->where('date', '>=', today());
        }

        $appointments = $query->paginate(50);

        return AppointmentResource::collection($appointments);
    }

    public function updateAppointmentStatus(Request $request, int $id)
    {
        $doctor = $request->user()->doctorProfile;

        $data = $request->validate([
            'status' => ['required', 'in:confirmed,checked_in,completed,cancelled,no_show'],
        ]);

        $appointment = Appointment::query()->where('doctor_profile_id', $doctor->id)->findOrFail($id);
        $previous = $appointment->status;
        $appointment->update(['status' => $data['status']]);

        if ($previous !== $data['status'] && $appointment->patient?->user) {
            $when = $appointment->date?->format('d M').' at '.substr((string) $appointment->start_time, 0, 5);
            $messages = [
                'confirmed' => ['Appointment confirmed', "Dr. {$request->user()->name} confirmed your appointment on {$when}."],
                'cancelled' => ['Appointment cancelled', "Dr. {$request->user()->name} cancelled your appointment on {$when}."],
                'completed' => ['Consultation completed', "Your consultation with Dr. {$request->user()->name} is complete."],
                'no_show' => ['Missed appointment', "You were marked as a no-show for your appointment on {$when}."],
            ];
            if (isset($messages[$data['status']])) {
                app(\App\Contracts\NotificationDispatcherInterface::class)->push(
                    $appointment->patient->user,
                    $messages[$data['status']][0],
                    $messages[$data['status']][1],
                    ['type' => 'appointment', 'data' => ['route' => 'appointment', 'params' => ['appointment_id' => $appointment->id], 'url' => '/account/appointments']]
                );
            }
        }

        return new AppointmentResource($appointment->fresh(['patient.user', 'clinic', 'service']));
    }

    public function patients(Request $request)
    {
        $doctor = $request->user()->doctorProfile;

        $patientIds = Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->distinct()
            ->pluck('patient_profile_id');

        $patients = PatientProfile::query()
            ->whereIn('id', $patientIds)
            ->with('user')
            ->get()
            ->map(function (PatientProfile $patient) use ($doctor) {
                $visits = Appointment::query()
                    ->where('doctor_profile_id', $doctor->id)
                    ->where('patient_profile_id', $patient->id)
                    ->count();

                $lastVisit = Appointment::query()
                    ->where('doctor_profile_id', $doctor->id)
                    ->where('patient_profile_id', $patient->id)
                    ->latest('date')
                    ->value('date');

                return [
                    'id' => $patient->id,
                    'name' => $patient->user?->name,
                    'phone' => $patient->user?->phone,
                    'gender' => $patient->gender,
                    'dob' => $patient->dob?->format('Y-m-d'),
                    'visits' => $visits,
                    'last_visit' => $lastVisit?->format('Y-m-d'),
                ];
            });

        return response()->json(['data' => $patients->values()]);
    }
}
