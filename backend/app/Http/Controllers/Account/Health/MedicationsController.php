<?php

namespace App\Http\Controllers\Account\Health;

use App\Http\Controllers\Account\Health\Concerns\ResolvesPatient;
use App\Http\Controllers\Controller;
use App\Models\Medication;
use App\Services\Health\PatientHealth;
use App\Support\HealthSettings;
use Illuminate\Http\Request;

class MedicationsController extends Controller
{
    use ResolvesPatient;

    public function index(Request $request)
    {
        $this->requireFeature('medications');
        $patient = $this->patient($request);

        return view('account.health.medications', [
            'medications' => PatientHealth::medications($patient),
            'today' => PatientHealth::todaysDoses($patient),
            'takenLog' => PatientHealth::takenLog($patient),
            'remindersOn' => HealthSettings::bool('health_medication_reminders_enabled'),
            'editing' => $request->integer('edit') ? $patient->medications()->find($request->integer('edit')) : null,
            'localToday' => now(HealthSettings::TIMEZONE)->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $this->requireFeature('medications');
        $patient = $this->patient($request);
        $attrs = PatientHealth::medicationAttributes($request->validate(PatientHealth::medicationRules()) + ['reminders_enabled' => $request->boolean('reminders_enabled')]);
        $patient->medications()->create($attrs);

        return redirect()->route('account.health.medications')->with('success', $attrs['name'].' added.');
    }

    public function update(Request $request, int $medication)
    {
        $this->requireFeature('medications');
        $record = $this->owned($request, $medication);
        $attrs = PatientHealth::medicationAttributes($request->validate(PatientHealth::medicationRules()) + ['reminders_enabled' => $request->boolean('reminders_enabled')]);
        $record->update($attrs);

        return redirect()->route('account.health.medications')->with('success', $record->name.' updated.');
    }

    public function destroy(Request $request, int $medication)
    {
        $this->requireFeature('medications');
        $record = $this->owned($request, $medication);
        $record->delete();

        return redirect()->route('account.health.medications')->with('success', $record->name.' removed.');
    }

    public function taken(Request $request, int $medication)
    {
        $this->requireFeature('medications');
        $record = $this->owned($request, $medication);
        $data = $request->validate(['time' => ['required', 'date_format:H:i'], 'undo' => ['nullable', 'boolean']]);

        try {
            PatientHealth::markTaken($record, $data['time'], ! $request->boolean('undo'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $request->boolean('undo')
            ? "{$record->name} ({$data['time']}) marked as not taken."
            : "{$record->name} ({$data['time']}) marked as taken.");
    }

    protected function owned(Request $request, int $id): Medication
    {
        return $this->patient($request)->medications()->findOrFail($id);
    }
}
