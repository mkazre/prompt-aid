<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Practice billing settings (Staff → Finance → Practice billing settings).
 * Stored in `settings`. Enforcement ships OFF so no existing practice is cut
 * off at deploy; the client switches it on once practices have subscribed.
 */
class PracticeBillingSettings
{
    public const DEFAULTS = [
        'practice_billing_enforced' => '0',        // master switch for the request gate
        'practice_billing_gate_instant' => '1',    // gate instant / see-a-doctor-now requests (dispatch)
        'practice_billing_gate_listing' => '0',    // hide lapsed practices from search / directory
        'practice_billing_gate_bookings' => '0',   // refuse new online bookings for lapsed practices
        'practice_billing_unsubscribed' => 'block', // practices with NO subscription at all: allow | block (when enforced)
        'practice_billing_retry_hours' => '24',    // after a missed renewal: past_due for this long (gateway retries / late ITN)
        'practice_billing_grace_days' => '7',      // then grace for this many days, then suspended
        'practice_billing_reminders' => '1',       // daily reminder (in-app + email) while past due / in grace
        'practice_billing_trial_reminder_days' => '3', // remind this many days before a trial ends (0 = off)
        'practice_billing_allow_simulation' => '0', // allow "Simulate renewal" in production (always on in non-production)
    ];

    public const FEATURES = [
        'instant' => 'practice_billing_gate_instant',
        'listing' => 'practice_billing_gate_listing',
        'bookings' => 'practice_billing_gate_bookings',
    ];

    public static function str(string $key): string
    {
        try {
            return (string) Setting::get($key, self::DEFAULTS[$key] ?? '');
        } catch (\Throwable) {
            return self::DEFAULTS[$key] ?? '';
        }
    }

    public static function int(string $key): int
    {
        return (int) self::str($key);
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::str($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    public static function enforced(): bool
    {
        return self::bool('practice_billing_enforced');
    }

    public static function featureGated(string $feature): bool
    {
        $key = self::FEATURES[$feature] ?? null;

        return $key !== null && self::bool($key);
    }

    public static function graceDays(): int
    {
        return max(0, min(60, self::int('practice_billing_grace_days')));
    }

    public static function retryHours(): int
    {
        return max(0, min(240, self::int('practice_billing_retry_hours')));
    }

    public static function simulationAllowed(): bool
    {
        return ! app()->environment('production') || self::bool('practice_billing_allow_simulation');
    }
}
