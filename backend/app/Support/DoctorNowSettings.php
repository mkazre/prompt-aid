<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Registry of every admin-configurable "See a doctor now" (on-demand doctor
 * matching) setting, with its default. Values live as key/value rows in
 * `settings`, edited from Staff → System → On-demand doctors. Defaults here
 * are identical to what doctor_now_migration.sql seeds, so a missing row
 * behaves exactly like a freshly seeded one. JSON settings are JSON strings.
 */
class DoctorNowSettings
{
    public const DEFAULTS = [
        // --- Matching ---
        'doctor_now_enabled' => '1',
        'doctor_now_offer_timeout_seconds' => '45',
        'doctor_now_max_attempts' => '5',
        'doctor_now_request_timeout_minutes' => '10',
        'doctor_now_heartbeat_stale_seconds' => '120',
        'doctor_now_inperson_radius_km' => '15',
        'doctor_now_location_stale_minutes' => '60',
        'doctor_now_waitlist_hours' => '4',

        // --- Pricing ---
        'doctor_now_timezone' => 'Africa/Johannesburg',
        'doctor_now_default_fee' => '450',
        // {"General Practitioner": 450, "Dermatologist": 850} — overrides the
        // median consultation_fee of active doctors in that specialty.
        'doctor_now_specialty_fees' => '{}',
        'doctor_now_time_bands' => '[{"label":"After hours","days":[1,2,3,4,5],"start":"18:00","end":"07:00","multiplier":1.3},{"label":"Weekend","days":[0,6],"start":"00:00","end":"23:59","multiplier":1.4}]',
        'doctor_now_public_holidays' => '["2026-01-01","2026-03-21","2026-04-03","2026-04-06","2026-04-27","2026-05-01","2026-06-16","2026-08-09","2026-08-10","2026-09-24","2026-12-16","2026-12-25","2026-12-26","2027-01-01","2027-03-22","2027-03-26","2027-03-29","2027-04-27","2027-05-01","2027-06-16","2027-08-09","2027-09-24","2027-12-16","2027-12-25","2027-12-27"]',
        'doctor_now_holiday_multiplier' => '1.5',
        'doctor_now_surge_enabled' => '1',
        'doctor_now_surge_steps' => '[{"ratio":1.5,"multiplier":1.15},{"ratio":2,"multiplier":1.3},{"ratio":3,"multiplier":1.5}]',
        'doctor_now_max_multiplier' => '2',

        // --- Cancellation after a doctor accepted ---
        'doctor_now_free_cancel_minutes' => '2',
        'doctor_now_cancel_fee_type' => 'fixed',
        'doctor_now_cancel_fee_amount' => '100',
        'doctor_now_cancel_fee_percent' => '25',
    ];

    public static function get(string $key): mixed
    {
        return Setting::get($key, self::DEFAULTS[$key] ?? null);
    }

    public static function str(string $key): string
    {
        return trim((string) self::get($key));
    }

    public static function float(string $key): float
    {
        $v = self::get($key);

        return is_numeric($v) ? (float) $v : (float) (self::DEFAULTS[$key] ?? 0);
    }

    public static function int(string $key): int
    {
        return (int) round(self::float($key));
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower((string) self::get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<int|string, mixed> */
    public static function json(string $key): array
    {
        $v = self::get($key);
        if (is_array($v)) {
            return $v;
        }
        $decoded = json_decode((string) $v, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $fallback = json_decode((string) (self::DEFAULTS[$key] ?? '[]'), true);

        return is_array($fallback) ? $fallback : [];
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_array($value)) {
            $value = json_encode(array_is_list($value) ? array_values($value) : $value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        Setting::set($key, $value === null ? null : (string) $value);
    }
}
