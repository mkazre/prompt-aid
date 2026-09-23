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
        $appointment->update(['status' => $data['status']]);

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
