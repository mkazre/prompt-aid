<?php

namespace App\Http\Controllers\Api\Health;

use App\Http\Controllers\Controller;
use App\Models\DoctorProfile;
use App\Models\FamilyMember;
use App\Models\Medication;
use App\Models\PatientProfile;
use App\Services\Health\PatientHealth;
use App\Support\HealthSettings;
use Illuminate\Http\Request;

/**
 * Patient self-care JSON API for the mobile app: favourites, family
 * members, medications (+ taken log), vitals and the medical ID card.
 * All scoped to the signed-in patient's own profile.
 */
class HealthController extends Controller
{
    protected function patient(Request $request): PatientProfile
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403, 'No patient profile on this account.');

        return $patient;
    }

    protected function requireFeature(string $feature): void
    {
        abort_unless(HealthSettings::feature($feature), 403, 'This feature is switched off.');
    }

    /** Feature switches + vitals thresholds, so the app can hide/show tiles. */
    public function config()
    {
        return response()->json([
            'features' => HealthSettings::features(),
            'vital_ranges' => HealthSettings::vitalRanges(),
            'vitals_note' => HealthSettings::get('health_vitals_note'),
            'medical_id_note' => HealthSettings::get('health_medical_id_note'),
            'relationships' => FamilyMember::RELATIONSHIPS,
            'blood_groups' => PatientHealth::BLOOD_GROUPS,
        ]);
    }

    // --- Favourite doctors -------------------------------------------------

    public function favourites(Request $request)
    {
        $this->requireFeature('favourites');

        return response()->json([
            'data' => PatientHealth::favouriteDoctors($this->patient($request))->map(fn ($d) => PatientHealth::doctorCard($d))->values(),
        ]);
    }

    public function addFavourite(Request $request)
    {
        $this->requireFeature('favourites');
        $data = $request->validate(['doctor_profile_id' => ['required', 'integer', 'exists:doctor_profiles,id']]);
        PatientHealth::addFavourite($this->patient($request), (int) $data['doctor_profile_id']);

        return response()->json(['favourite' => true, 'doctor_profile_id' => (int) $data['doctor_profile_id']], 201);
    }

    public function removeFavourite(Request $request, ?int $doctor = null)
    {
        $this->requireFeature('favourites');
        $id = $doctor ?? $request->integer('doctor_profile_id');
        abort_unless($id, 422, 'doctor_profile_id is required.');
        PatientHealth::removeFavourite($this->patient($request), $id);

        return response()->json(['favourite' => false, 'doctor_profile_id' => $id]);
    }

    // --- Family members ----------------------------------------------------

    public function family(Request $request)
    {
        $this->requireFeature('family');

        return response()->json([
            'data' => $this->patient($request)->familyMembers()->orderBy('name')->get()->map->toApi()->values(),
            'relationships' => FamilyMember::RELATIONSHIPS,
        ]);
    }

    public function storeFamily(Request $request)
    {
        $this->requireFeature('family');
        $patient = $this->patient($request);
        $data = $request->validate(PatientHealth::familyRules());
        abort_if($patient->familyMembers()->count() >= 20, 422, 'You can add up to 20 family members.');

        return response()->json(['data' => $patient->familyMembers()->create($data)->toApi()], 201);
    }

    public function updateFamily(Request $request, int $id)
    {
        $this->requireFeature('family');
        $member = $this->patient($request)->familyMembers()->findOrFail($id);
        $member->update($request->validate(PatientHealth::familyRules()));

        return response()->json(['data' => $member->fresh()->toApi()]);
    }

    public function destroyFamily(Request $request, int $id)
    {
        $this->requireFeature('family');
        $this->patient($request)->familyMembers()->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // --- Medications -------------------------------------------------------

    public function medications(Request $request)
    {
        $this->requireFeature('medications');
        $patient = $this->patient($request);
        $now = now(HealthSettings::TIMEZONE);

        return response()->json([
            'data' => PatientHealth::medications($patient)->map(fn (Medication $m) => $m->toApi($now))->values(),
            'today' => PatientHealth::todaysDoses($patient),
            'taken_log' => PatientHealth::takenLog($patient)->map(fn ($d) => [
                'medication' => $d->medication?->name,
                'dose' => $d->medication?->dose,
                'date' => $d->dose_date->toDateString(),
                'time' => $d->dose_time,
                'taken_at' => $d->taken_at?->copy()->setTimezone(HealthSettings::TIMEZONE)->format('Y-m-d H:i'),
            ])->values(),
            'reminders_enabled' => HealthSettings::bool('health_medication_reminders_enabled'),
            'local_date' => $now->toDateString(),
            'local_time' => $now->format('H:i'),
        ]);
    }

    public function storeMedication(Request $request)
    {
        $this->requireFeature('medications');
        $attrs = PatientHealth::medicationAttributes($request->validate(PatientHealth::medicationRules()));
        $med = $this->patient($request)->medications()->create($attrs);

        return response()->json(['data' => $med->toApi()], 201);
    }

    public function updateMedication(Request $request, int $id)
    {
        $this->requireFeature('medications');
        $med = $this->patient($request)->medications()->findOrFail($id);
        $med->update(PatientHealth::medicationAttributes($request->validate(PatientHealth::medicationRules())));

        return response()->json(['data' => $med->fresh()->toApi()]);
    }

    public function destroyMedication(Request $request, int $id)
    {
        $this->requireFeature('medications');
        $this->patient($request)->medications()->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function medicationTaken(Request $request, int $id)
    {
        $this->requireFeature('medications');
        $med = $this->patient($request)->medications()->findOrFail($id);
        $data = $request->validate(['time' => ['required', 'date_format:H:i'], 'taken' => ['nullable', 'boolean']]);

        try {
            PatientHealth::markTaken($med, $data['time'], $request->has('taken') ? $request->boolean('taken') : true);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $med->fresh()->toApi()]);
    }

    // --- Vitals ------------------------------------------------------------

    public function vitals(Request $request)
    {
        $this->requireFeature('vitals');
        $readings = $this->patient($request)->vitalReadings()->orderByDesc('recorded_at')->limit(100)->get();
        $ranges = HealthSettings::vitalRanges();

        return response()->json([
            'data' => $readings->map(fn ($r) => $r->toApi($ranges))->values(),
            'series' => (object) PatientHealth::vitalSeries($readings),
            'ranges' => $ranges,
            'note' => HealthSettings::get('health_vitals_note'),
        ]);
    }

    public function storeVital(Request $request)
    {
        $this->requireFeature('vitals');
        $reading = PatientHealth::createVital($this->patient($request), $request->validate(PatientHealth::vitalRules()), $request->user()->id);

        return response()->json(['data' => $reading->toApi(), 'flag' => $reading->worstFlag()], 201);
    }

    public function destroyVital(Request $request, int $id)
    {
        $this->requireFeature('vitals');
        $this->patient($request)->vitalReadings()->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // --- Medical ID --------------------------------------------------------

    public function medicalId(Request $request)
    {
        $this->requireFeature('medical_id');

        return response()->json(['data' => PatientHealth::medicalId($this->patient($request))]);
    }

    public function updateMedicalId(Request $request)
    {
        $this->requireFeature('medical_id');
        $patient = $this->patient($request);
        $patient->update($request->validate(PatientHealth::medicalIdRules()));

        return response()->json(['data' => PatientHealth::medicalId($patient->fresh())]);
    }
}
