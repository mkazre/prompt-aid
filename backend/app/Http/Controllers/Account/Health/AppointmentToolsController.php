<?php

namespace App\Http\Controllers\Account\Health;

use App\Http\Controllers\Account\Health\Concerns\ResolvesPatient;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Health\AppointmentCalendar;
use App\Services\Health\PatientRescheduleService;
use App\Support\HealthSettings;
use Illuminate\Http\Request;

/** Patient reschedule (date picker + slot chooser) and the .ics download. */
class AppointmentToolsController extends Controller
{
    use ResolvesPatient;

    public function __construct(protected PatientRescheduleService $reschedule) {}

    protected function owned(Request $request, int $id): Appointment
    {
        return $this->patient($request)->appointments()->with(['doctor.user', 'clinic'])->findOrFail($id);
    }

    public function reschedule(Request $request, int $appointment)
    {
        $appt = $this->owned($request, $appointment);
        if ($reason = $this->reschedule->blockReason($appt)) {
            return redirect()->route('account.appointments')->with('error', $reason);
        }

        $today = now(HealthSettings::TIMEZONE)->toDateString();

        return view('account.health.reschedule', [
            'appointment' => $appt,
            'minDate' => $today,
            'maxDate' => now(HealthSettings::TIMEZONE)->addDays(90)->toDateString(),
            'initialDate' => max($today, $appt->date->toDateString()),
        ]);
    }

    public function slots(Request $request, int $appointment)
    {
        $appt = $this->owned($request, $appointment);
        if ($reason = $this->reschedule->blockReason($appt)) {
            return response()->json(['message' => $reason], 422);
        }
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(90)->toDateString()]]);

        return response()->json($this->reschedule->slots($appt, $data['date']));
    }

    public function storeReschedule(Request $request, int $appointment)
    {
        $appt = $this->owned($request, $appointment);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
        ], ['start_time.required' => 'Choose a new time.']);

        try {
            $this->reschedule->reschedule($appt, $data['date'], $data['start_time']);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('account.appointments')->with('success', 'Appointment moved to '.\Carbon\Carbon::parse($data['date'])->format('D j M').' at '.$data['start_time'].'. Your doctor has been notified.');
    }

    public function calendar(Request $request, int $appointment, AppointmentCalendar $calendar)
    {
        $this->requireFeature('calendar');
        $appt = $this->owned($request, $appointment);
        abort_unless($appt->doctor_profile_id && $appt->date && $appt->start_time, 404);

        return $calendar->download($appt);
    }
}
