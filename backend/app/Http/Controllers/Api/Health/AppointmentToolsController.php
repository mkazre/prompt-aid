<?php

namespace App\Http\Controllers\Api\Health;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\Health\AppointmentCalendar;
use App\Services\Health\PatientRescheduleService;
use App\Support\HealthSettings;
use Illuminate\Http\Request;

/** Patient reschedule + add-to-calendar for the mobile app. */
class AppointmentToolsController extends Controller
{
    public function __construct(protected PatientRescheduleService $reschedule) {}

    protected function owned(Request $request, int $id): Appointment
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403);

        return $patient->appointments()->with(['doctor.user', 'clinic'])->findOrFail($id);
    }

    public function slots(Request $request, int $id)
    {
        $appointment = $this->owned($request, $id);
        if ($reason = $this->reschedule->blockReason($appointment)) {
            return response()->json(['message' => $reason], 422);
        }
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(90)->toDateString()]]);

        return response()->json($this->reschedule->slots($appointment, $data['date']));
    }

    public function reschedule(Request $request, int $id)
    {
        $appointment = $this->owned($request, $id);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
        ]);

        try {
            $this->reschedule->reschedule($appointment, $data['date'], $data['start_time']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new AppointmentResource($appointment->fresh(['doctor.user', 'clinic', 'service']));
    }

    /** A short-lived signed .ics URL the app opens with Linking.openURL. */
    public function calendarLink(Request $request, int $id, AppointmentCalendar $calendar)
    {
        abort_unless(HealthSettings::feature('calendar'), 403, 'This feature is switched off.');
        $appointment = $this->owned($request, $id);
        abort_unless($appointment->doctor_profile_id && $appointment->date && $appointment->start_time, 422, 'This appointment has no time yet.');

        return response()->json(['url' => $calendar->signedUrl($appointment), 'expires_in' => 1800]);
    }

    /** Public, signed: no session or token needed. */
    public function signedCalendar(int $appointment, AppointmentCalendar $calendar)
    {
        abort_unless(HealthSettings::feature('calendar'), 404);

        return $calendar->download(Appointment::query()->findOrFail($appointment));
    }
}
