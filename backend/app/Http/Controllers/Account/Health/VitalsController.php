<?php

namespace App\Http\Controllers\Account\Health;

use App\Http\Controllers\Account\Health\Concerns\ResolvesPatient;
use App\Http\Controllers\Controller;
use App\Services\Health\PatientHealth;
use App\Support\HealthSettings;
use Illuminate\Http\Request;

class VitalsController extends Controller
{
    use ResolvesPatient;

    public function index(Request $request)
    {
        $this->requireFeature('vitals');
        $patient = $this->patient($request);
        $readings = $patient->vitalReadings()->orderByDesc('recorded_at')->limit(100)->get();

        return view('account.health.vitals', [
            'readings' => $readings,
            'series' => PatientHealth::vitalSeries($readings),
            'ranges' => HealthSettings::vitalRanges(),
            'note' => HealthSettings::get('health_vitals_note'),
        ]);
    }

    public function store(Request $request)
    {
        $this->requireFeature('vitals');
        $patient = $this->patient($request);
        $reading = PatientHealth::createVital($patient, $request->validate(PatientHealth::vitalRules()), $request->user()->id);

        $flag = $reading->worstFlag();

        return redirect()->route('account.health.vitals')->with($flag ? 'error' : 'success', match ($flag) {
            'red' => 'Saved. Some readings are far outside the normal range — please contact your doctor, or call for help if you feel unwell.',
            'orange' => 'Saved. Some readings are outside the normal range.',
            default => 'Reading saved.',
        });
    }

    public function destroy(Request $request, int $reading)
    {
        $this->requireFeature('vitals');
        $this->patient($request)->vitalReadings()->findOrFail($reading)->delete();

        return redirect()->route('account.health.vitals')->with('success', 'Reading deleted.');
    }
}
