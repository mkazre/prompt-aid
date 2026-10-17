<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin-configurable unified dispatch settings (Staff → System →
 * Dispatch settings). Same key/value `settings` storage as
 * ShuttleSettings; a missing row behaves like the default below.
 *
 * Modes per service type:
 *   broadcast  — offer to EVERY qualifying online provider at once; the
 *                first accept wins (atomic), everyone else sees "taken".
 *   sequential — one provider at a time (the original shuttle / doctor-now
 *                behaviour).
 *   manual     — nothing is offered automatically; an admin assigns from
 *                Live requests and the provider gets an "assigned" alert.
 */
class DispatchSettings
{
    public const MODES = ['broadcast' => 'Broadcast to all qualifying providers (first accept wins)', 'sequential' => 'Sequential (one provider at a time)', 'manual' => 'Manual (admin assigns)'];

    /** service key => label */
    public const SERVICES = [
        'shuttle' => 'Shuttle rides',
        'doctor_now' => 'See a doctor now',
        'doctor_later' => 'Any doctor later today / tomorrow',
        'appointment' => 'Appointment with a specific doctor',
        'pharmacy_order' => 'Medicine orders (pharmacy)',
        'script_review' => 'Prescription (script) review',
        'lab_request' => 'Lab / allied requests',
    ];

    public const DEFAULTS = [
        'dispatch_mode_shuttle' => 'broadcast',
        'dispatch_mode_doctor_now' => 'broadcast',
        'dispatch_mode_doctor_later' => 'broadcast',
        'dispatch_mode_appointment' => 'broadcast',
        'dispatch_mode_pharmacy_order' => 'broadcast',
        'dispatch_mode_script_review' => 'broadcast',
        'dispatch_mode_lab_request' => 'broadcast',

        // Offers / requests
        'dispatch_offer_timeout_seconds' => '45',
        'dispatch_request_expiry_minutes' => '15',
        'dispatch_scheduled_expiry_minutes' => '1440',
        'dispatch_heartbeat_stale_seconds' => '120',

        // Radius
        'dispatch_default_radius_km' => '10',
        'dispatch_max_radius_km' => '50',

        // Auto-close (stale clean-up). 0 = off.
        'dispatch_ride_searching_expiry_minutes' => '15',
        'dispatch_ride_scheduled_grace_minutes' => '60',
        'dispatch_ride_active_stale_hours' => '6',
        'dispatch_appt_autoclose_hours' => '24',
        'dispatch_appt_autoclose_status' => 'completed',
        'dispatch_order_pending_payment_hours' => '0',
        'dispatch_lab_unassigned_days' => '0',
        'dispatch_doctor_now_matched_hours' => '6',

        // Trip PIN
        'dispatch_pin_max_attempts' => '5',
        'dispatch_pin_lock_minutes' => '10',

        // Alerts
        'dispatch_sound_enabled' => '1',
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

    public static function set(string $key, mixed $value): void
    {
        Setting::set($key, is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value));
    }

    public static function mode(string $service): string
    {
        $m = self::str('dispatch_mode_'.$service);

        return array_key_exists($m, self::MODES) ? $m : 'broadcast';
    }

    public static function offerTimeout(): int
    {
        return max(10, min(600, self::int('dispatch_offer_timeout_seconds')));
    }

    public static function defaultRadius(): float
    {
        return max(1.0, self::float('dispatch_default_radius_km'));
    }

    public static function maxRadius(): float
    {
        return max(self::defaultRadius(), self::float('dispatch_max_radius_km'));
    }

    /** Clamp a patient-chosen radius to [1, admin max]; null → default. */
    public static function clampRadius(mixed $km): float
    {
        if (! is_numeric($km) || (float) $km <= 0) {
            return self::defaultRadius();
        }

        return round(max(1.0, min(self::maxRadius(), (float) $km)), 1);
    }

    /** Radius options offered in patient UIs (km), capped at the admin max. */
    public static function radiusOptions(): array
    {
        $max = self::maxRadius();

        return array_values(array_filter([2, 5, 10, 15, 25, 50, 100], fn ($v) => $v <= $max)) ?: [(int) $max];
    }
}
