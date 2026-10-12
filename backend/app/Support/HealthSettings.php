<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin-configurable switches, copy and thresholds for the patient
 * self-care features (favourite doctors, family members, medications,
 * vitals, medical ID, patient reschedule, add-to-calendar and appointment
 * reminders). Values are key/value rows in `settings`, edited from
 * Staff → System → Patient health settings. A missing row behaves like
 * the default below.
 */
class HealthSettings
{
    /** feature key => admin label */
    public const FEATURES = [
        'favourites' => 'Favourite doctors ("My doctors")',
        'family' => 'Family members (dependants)',
        'medications' => 'Medications list',
        'vitals' => 'Vitals tracker',
        'medical_id' => 'Medical ID card',
        'reschedule' => 'Patients can reschedule appointments',
        'calendar' => 'Add to calendar (.ics)',
    ];

    /**
     * Vitals with a normal (orange outside) and a critical (red outside)
     * range. metric => [label, unit, normal_min, normal_max, critical_min, critical_max]
     */
    public const VITALS = [
        'systolic' => ['Systolic BP', 'mmHg', 90, 139, 80, 180],
        'diastolic' => ['Diastolic BP', 'mmHg', 60, 89, 50, 120],
        'pulse' => ['Pulse', 'bpm', 50, 100, 40, 130],
        'glucose' => ['Blood glucose', 'mmol/L', 4.0, 7.8, 3.0, 13.9],
        'temperature' => ['Temperature', '°C', 35.5, 37.8, 35.0, 39.5],
        'spo2' => ['Oxygen saturation', '%', 95, 100, 90, 100],
    ];

    public const DEFAULTS = [
        // Feature switches (1/0).
        'health_feature_favourites' => '1',
        'health_feature_family' => '1',
        'health_feature_medications' => '1',
        'health_feature_vitals' => '1',
        'health_feature_medical_id' => '1',
        'health_feature_reschedule' => '1',
        'health_feature_calendar' => '1',

        // Medication reminders (platform-wide on/off + copy).
        'health_medication_reminders_enabled' => '1',
        'health_medication_reminder_title' => 'Time for your medication',
        'health_medication_reminder_body' => 'Take {dose} of {name}',
        // A dose counts as missed this many minutes after its time.
        'health_medication_missed_after_minutes' => '60',

        // Patient reschedule: minimum notice before the current start.
        'health_reschedule_min_notice_hours' => '2',

        // Appointment reminders.
        'health_appt_reminders_enabled' => '1',
        'health_appt_reminder_first_hours' => '24',
        'health_appt_reminder_second_minutes' => '60',
        'health_appt_reminder_title' => 'Appointment reminder',
        'health_appt_reminder_patient_body' => 'Your appointment with {doctor} is {date} at {time}{place}.',
        'health_appt_reminder_doctor_body' => '{patient} is booked with you {date} at {time}{place}.',

        // Copy.
        'health_medical_id_note' => 'Show this card to emergency staff. Keep your allergies, conditions and medications up to date.',
        'health_vitals_note' => 'Readings outside the normal range are flagged orange, and red when they are far outside it. This is not a diagnosis — contact your doctor or call for help if you feel unwell.',
    ];

    public const TIMEZONE = 'Africa/Johannesburg';

    public static function get(string $key): string
    {
        return (string) Setting::get($key, self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key): int
    {
        return max(0, (int) self::get($key));
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function feature(string $feature): bool
    {
        return self::bool("health_feature_{$feature}");
    }

    /** @return array<string, bool> */
    public static function features(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $f) {
            $out[$f] = self::feature($f);
        }
        $out['medication_reminders'] = self::bool('health_medication_reminders_enabled');

        return $out;
    }

    /**
     * @return array<string, array{label: string, unit: string, normal_min: float, normal_max: float, critical_min: float, critical_max: float}>
     */
    public static function vitalRanges(): array
    {
        $out = [];
        foreach (self::VITALS as $metric => [$label, $unit, $nMin, $nMax, $cMin, $cMax]) {
            $out[$metric] = [
                'label' => $label,
                'unit' => $unit,
                'normal_min' => (float) Setting::get("health_vital_{$metric}_normal_min", $nMin),
                'normal_max' => (float) Setting::get("health_vital_{$metric}_normal_max", $nMax),
                'critical_min' => (float) Setting::get("health_vital_{$metric}_critical_min", $cMin),
                'critical_max' => (float) Setting::get("health_vital_{$metric}_critical_max", $cMax),
            ];
        }

        return $out;
    }

    /**
     * Flag for one reading: null (in range), 'orange' or 'red'.
     */
    public static function vitalFlag(string $metric, mixed $value, ?array $ranges = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ranges ??= self::vitalRanges();
        $r = $ranges[$metric] ?? null;
        if (! $r) {
            return null;
        }
        $v = (float) $value;
        if ($v < $r['critical_min'] || $v > $r['critical_max']) {
            return 'red';
        }
        if ($v < $r['normal_min'] || $v > $r['normal_max']) {
            return 'orange';
        }

        return null;
    }

    /** Replace {placeholders} in admin copy. */
    public static function fill(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{'.$k.'}'] = (string) $v;
        }

        return strtr($template, $map);
    }
}
