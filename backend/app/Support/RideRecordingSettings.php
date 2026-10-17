<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin-editable settings for trip audio recordings (safety evidence).
 * Stored as `settings` rows, edited at /staff → Ride Service →
 * Trip recording settings (App\Filament\Pages\RideRecordingSettingsPage).
 * A missing row behaves like the default below.
 */
class RideRecordingSettings
{
    public const DEFAULTS = [
        'ride_recording_enabled_patient' => '1',
        'ride_recording_enabled_driver' => '1',
        // Days a recording is kept after it ends (unless on legal hold).
        'ride_recording_retention_days' => '3',
        // Hard cap on one recording's length; the recorder stops itself.
        'ride_recording_max_minutes' => '120',
        // Length of each uploaded piece (app + web). 10–120.
        'ride_recording_chunk_seconds' => '30',
        // Hold automatically when the ride had an SOS or a party has an open shuttle support ticket.
        'ride_recording_auto_hold' => '1',
    ];

    /** Upload limits that are not admin settings. */
    public const MAX_CHUNK_BYTES = 20 * 1024 * 1024;

    public const MAX_RECORDING_BYTES = 300 * 1024 * 1024;

    /** Minutes after a ride completes/cancels during which a recording may still be STARTED. */
    public const GRACE_MINUTES = 15;

    /** Minutes after the recording's own max length during which queued (offline) chunks are still accepted. */
    public const LATE_UPLOAD_MINUTES = 120;

    public const MIMES = ['audio/m4a', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/webm', 'audio/ogg', 'audio/mpeg'];

    public static function get(string $key): mixed
    {
        try {
            return Setting::get($key, self::DEFAULTS[$key] ?? null);
        } catch (\Throwable $e) {
            return self::DEFAULTS[$key] ?? null;
        }
    }

    public static function int(string $key): int
    {
        $v = self::get($key);

        return is_numeric($v) ? (int) $v : (int) (self::DEFAULTS[$key] ?? 0);
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(trim((string) self::get($key))), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        Setting::set($key, $value === null ? '' : (string) $value);
    }

    public static function retentionDays(): int
    {
        return max(1, min(3650, self::int('ride_recording_retention_days')));
    }

    public static function maxMinutes(): int
    {
        return max(1, min(600, self::int('ride_recording_max_minutes')));
    }

    public static function chunkSeconds(): int
    {
        return max(10, min(120, self::int('ride_recording_chunk_seconds')));
    }

    public static function enabledFor(string $role): bool
    {
        return self::bool($role === 'driver' ? 'ride_recording_enabled_driver' : 'ride_recording_enabled_patient');
    }

    /** Normalise a client-reported mime ("audio/webm;codecs=opus" → "audio/webm"). Null if not allowed. */
    public static function normaliseMime(?string $mime): ?string
    {
        $base = strtolower(trim(explode(';', (string) $mime)[0]));
        if ($base === 'audio/x-m4a' || $base === 'audio/mp4') {
            $base = 'audio/m4a';
        }

        return in_array($base, self::MIMES, true) ? $base : null;
    }
}
