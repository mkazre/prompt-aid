<?php

namespace App\Services\Health;

use App\Models\DoctorProfile;
use App\Models\EmergencyContact;
use App\Models\FamilyMember;
use App\Models\FavouriteDoctor;
use App\Models\Medication;
use App\Models\PatientProfile;
use App\Models\VitalReading;
use App\Support\HealthSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Shared logic for the patient self-care features, used by both the web
 * account controllers and the JSON API so they behave identically:
 * validation rules, favourites, medication/vitals payloads and the
 * medical ID card.
 */
class PatientHealth
{
    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public static function familyRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', Rule::in(array_keys(FamilyMember::RELATIONSHIPS))],
            'dob' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(array_keys(FamilyMember::GENDERS))],
            'id_number' => ['nullable', 'string', 'max:40'],
            'medical_aid_scheme' => ['nullable', 'string', 'max:255'],
            'medical_aid_number' => ['nullable', 'string', 'max:60'],
            'allergies' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function medicationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'dose' => ['required', 'string', 'max:120'],
            'times' => ['required'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reminders_enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Validated medication input → model attributes (times normalised).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function medicationAttributes(array $data): array
    {
        $times = Medication::parseTimes($data['times'] ?? []);
        if (! $times) {
            throw \Illuminate\Validation\ValidationException::withMessages(['times' => 'Add at least one time of day as HH:MM, e.g. 08:00, 20:00.']);
        }
        if (count($times) > 12) {
            throw \Illuminate\Validation\ValidationException::withMessages(['times' => 'Up to 12 times a day.']);
        }

        return [
            'name' => $data['name'],
            'dose' => $data['dose'],
            'times' => $times,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'reminders_enabled' => array_key_exists('reminders_enabled', $data) ? (bool) $data['reminders_enabled'] : true,
        ];
    }

    public static function vitalRules(): array
    {
        return [
            'recorded_at' => ['nullable', 'date', 'before_or_equal:'.now(HealthSettings::TIMEZONE)->addMinutes(5)->format('Y-m-d H:i:s')],
            'systolic' => ['nullable', 'integer', 'between:40,300', 'required_with:diastolic'],
            'diastolic' => ['nullable', 'integer', 'between:20,200', 'required_with:systolic'],
            'pulse' => ['nullable', 'integer', 'between:20,250'],
            'glucose' => ['nullable', 'numeric', 'between:0.5,60'],
            'weight' => ['nullable', 'numeric', 'between:0.5,500'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'spo2' => ['nullable', 'integer', 'between:50,100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function createVital(PatientProfile $patient, array $data, ?int $byUserId): VitalReading
    {
        $hasValue = collect(VitalReading::METRICS)->contains(fn ($m) => isset($data[$m]) && $data[$m] !== '');
        if (! $hasValue) {
            throw \Illuminate\Validation\ValidationException::withMessages(['systolic' => 'Enter at least one reading.']);
        }

        $attrs = ['recorded_by_user_id' => $byUserId, 'note' => $data['note'] ?? null];
        foreach (VitalReading::METRICS as $m) {
            $attrs[$m] = isset($data[$m]) && $data[$m] !== '' ? $data[$m] : null;
        }
        // Entered as local time; stored in the app timezone.
        $attrs['recorded_at'] = ! empty($data['recorded_at'])
            ? Carbon::parse($data['recorded_at'], HealthSettings::TIMEZONE)->setTimezone(config('app.timezone'))
            : now();

        return $patient->vitalReadings()->create($attrs);
    }

    // ---------------------------------------------------------------
    // Favourites
    // ---------------------------------------------------------------

    /** @return Collection<int, DoctorProfile> */
    public static function favouriteDoctors(PatientProfile $patient): Collection
    {
        $ids = $patient->favouriteDoctors()->latest()->pluck('doctor_profile_id');

        $doctors = DoctorProfile::query()->whereIn('id', $ids)->with(['user', 'clinics'])->get()->keyBy('id');

        return $ids->map(fn ($id) => $doctors->get($id))->filter()->values();
    }

    public static function isFavourite(?PatientProfile $patient, int $doctorId): bool
    {
        return $patient ? $patient->favouriteDoctors()->where('doctor_profile_id', $doctorId)->exists() : false;
    }

    public static function addFavourite(PatientProfile $patient, int $doctorId): void
    {
        FavouriteDoctor::query()->firstOrCreate(['patient_profile_id' => $patient->id, 'doctor_profile_id' => $doctorId]);
    }

    public static function removeFavourite(PatientProfile $patient, int $doctorId): void
    {
        $patient->favouriteDoctors()->where('doctor_profile_id', $doctorId)->delete();
    }

    public static function doctorCard(DoctorProfile $doctor): array
    {
        return [
            'id' => $doctor->id,
            'name' => $doctor->displayName(),
            'specialization' => $doctor->specialization,
            'avatar_url' => \App\Support\Widgets\WidgetDataService::mediaUrl($doctor->user?->avatar),
            'rating_avg' => (float) $doctor->rating_avg,
            'rating_count' => (int) $doctor->rating_count,
            'consultation_fee' => (float) $doctor->consultation_fee,
            'clinic' => $doctor->clinics->first()?->name,
            'live_status' => $doctor->liveStatus(),
        ];
    }

    // ---------------------------------------------------------------
    // Medications
    // ---------------------------------------------------------------

    /** @return Collection<int, Medication> current + future first, then ended */
    public static function medications(PatientProfile $patient): Collection
    {
        $today = now(HealthSettings::TIMEZONE)->toDateString();

        return $patient->medications()->with(['doses' => fn ($q) => $q->whereDate('dose_date', $today)])->orderBy('name')->get()
            ->sortBy(fn (Medication $m) => ($m->end_date && $m->end_date->toDateString() < $today) ? 1 : 0)
            ->values();
    }

    /**
     * Today's schedule across all medications, sorted by time.
     *
     * @return array<int, array{medication_id: int, name: string, dose: string, time: string, status: string, taken_at: ?string}>
     */
    public static function todaysDoses(PatientProfile $patient): array
    {
        $now = now(HealthSettings::TIMEZONE);
        $rows = [];
        foreach (self::medications($patient) as $med) {
            foreach ($med->dosesFor($now, $med->doses) as $dose) {
                $rows[] = ['medication_id' => $med->id, 'name' => $med->name, 'dose' => $med->dose] + $dose;
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['time'], $b['time']) ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** Recent taken log (last N days). */
    public static function takenLog(PatientProfile $patient, int $days = 14): Collection
    {
        return \App\Models\MedicationDose::query()
            ->whereIn('medication_id', $patient->medications()->pluck('id'))
            ->whereNotNull('taken_at')
            ->whereDate('dose_date', '>=', now(HealthSettings::TIMEZONE)->subDays($days)->toDateString())
            ->with('medication:id,name,dose')
            ->orderByDesc('dose_date')->orderByDesc('dose_time')
            ->limit(100)
            ->get();
    }

    /**
     * @throws \RuntimeException
     */
    public static function markTaken(Medication $medication, string $time, bool $taken = true): void
    {
        $now = now(HealthSettings::TIMEZONE);
        $time = substr($time, 0, 5);
        if (! in_array($time, $medication->doseTimes(), true)) {
            throw new \RuntimeException('That dose time is not on this medication.');
        }
        if (! $medication->isActiveOn($now->toDateString())) {
            throw new \RuntimeException('This medication is not active today.');
        }

        $dose = $medication->doses()->firstOrNew(['dose_date' => $now->toDateString(), 'dose_time' => $time]);
        $dose->taken_at = $taken ? now() : null;
        $dose->save();
    }

    // ---------------------------------------------------------------
    // Vitals
    // ---------------------------------------------------------------

    /**
     * Series for sparklines (oldest → newest) for each metric that has data.
     *
     * @param  Collection<int, VitalReading>  $readings  newest first
     * @return array<string, array<int, float>>
     */
    public static function vitalSeries(Collection $readings, int $points = 20): array
    {
        $series = [];
        foreach (VitalReading::METRICS as $m) {
            $values = $readings->filter(fn ($r) => $r->{$m} !== null)->take($points)->reverse()->map(fn ($r) => (float) $r->{$m})->values()->all();
            if ($values) {
                $series[$m] = $values;
            }
        }

        return $series;
    }

    // ---------------------------------------------------------------
    // Medical ID
    // ---------------------------------------------------------------

    public static function medicalId(PatientProfile $patient): array
    {
        $patient->loadMissing('user');
        $user = $patient->user;
        $membership = $patient->schemeMemberships()->with('scheme')->latest()->first();
        $today = now(HealthSettings::TIMEZONE)->toDateString();
        $meds = $patient->medications()->activeOn($today)->orderBy('name')->get();

        return [
            'name' => $user?->name,
            'initials' => collect(explode(' ', trim((string) $user?->name)))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode(''),
            'photo_url' => $user?->avatar ? \App\Support\Widgets\WidgetDataService::mediaUrl($user->avatar) : null,
            'dob' => $patient->dob?->toDateString(),
            'age' => $patient->dob?->age,
            'gender' => $patient->gender,
            'phone' => $user?->phone,
            'blood_group' => $patient->blood_group,
            'allergies' => $patient->allergies,
            'chronic_conditions' => $patient->chronic_conditions,
            'medications' => $meds->map(fn (Medication $m) => [
                'name' => $m->name,
                'dose' => $m->dose,
                'times' => $m->doseTimes(),
            ])->values()->all(),
            'emergency_contact' => [
                'name' => $patient->emergency_contact_name,
                'phone' => $patient->emergency_contact_phone,
            ],
            'emergency_numbers' => EmergencyContact::activeOrdered()->map(fn ($c) => [
                'label' => $c->label,
                'phone' => $c->phone,
                'is_primary' => (bool) $c->is_primary,
            ])->values()->all(),
            'medical_aid' => $membership ? [
                'scheme' => $membership->scheme?->name,
                'member_number' => $membership->member_number,
                'dependant_code' => $membership->dependant_code,
                'main_member' => $membership->main_member_name,
            ] : null,
            'note' => HealthSettings::get('health_medical_id_note'),
        ];
    }

    public static function medicalIdRules(): array
    {
        return [
            'blood_group' => ['nullable', 'string', 'max:10'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'chronic_conditions' => ['nullable', 'string', 'max:2000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
}
