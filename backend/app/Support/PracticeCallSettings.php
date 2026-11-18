<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin settings for the orange "practice call" flow, call records and
 * recording storage (Staff → System → Practice calls / Recording storage).
 * Stored as `settings` rows; a missing row behaves like the default below.
 * Secrets (Bunny storage key, Bunny token key, JaaS webhook secret) are
 * stored encrypted and never rendered back.
 */
class PracticeCallSettings
{
    public const DEFAULTS = [
        // --- Ringing both sides after a practice accepted ---
        'practice_call_ring_seconds' => '45',
        // Automatic re-rings when a side does not answer in time (0 = ring once).
        'practice_call_max_rings' => '3',
        // Return the request to the queue for other practices when the call has not
        // connected this many seconds after the practice accepted (0 = never).
        'practice_call_requeue_seconds' => '180',
        // Max practices (offers) alerted at once per broadcast round.
        'practice_call_max_practices' => '60',
        // Shuttle pickup = appointment start − route ETA − this buffer.
        'practice_call_shuttle_buffer_minutes' => '15',
        // Return leg offered by default.
        'practice_call_shuttle_return_default' => '0',

        // --- Recording (POPIA / RICA: both parties are told at the start) ---
        'call_recording_enabled' => '1',
        // auto = JaaS server recording when JaaS is the video provider, else in-browser recording on the practice side.
        'call_recording_mode' => 'auto', // auto | jaas | client | off
        'call_recording_video_consults' => '0',
        'call_recording_notice' => 'This call is recorded for your medical record, quality and safety, as required by POPIA. Both sides have been notified.',
        'call_recording_retention_days' => '1825',
        'call_recording_max_minutes' => '90',

        // --- Storage ---
        'recording_storage_driver' => 'auto', // auto (Bunny when configured, else local) | bunny | local
        'bunny_storage_zone' => '',
        'bunny_storage_region' => '', // '' = Falkenstein (storage.bunnycdn.com); uk, ny, la, sg, se, br, jh, syd
        'bunny_storage_hostname' => '', // optional override, e.g. jh.storage.bunnycdn.com
        'bunny_storage_key' => '', // encrypted
        'bunny_pull_zone_url' => '', // optional, e.g. https://promptaid-rec.b-cdn.net
        'bunny_token_key' => '', // encrypted, Pull Zone → Security → Token authentication key
        'bunny_signed_url_minutes' => '10',
        'bunny_path_prefix' => 'call-recordings',

        // --- JaaS (8x8) webhook for RECORDING_UPLOADED ---
        'jaas_webhook_secret' => '', // encrypted
    ];

    public const SECRETS = ['bunny_storage_key', 'bunny_token_key', 'jaas_webhook_secret'];

    public const REGIONS = [
        '' => 'Falkenstein, DE (storage.bunnycdn.com)',
        'uk' => 'London, UK',
        'se' => 'Stockholm, SE',
        'ny' => 'New York, US',
        'la' => 'Los Angeles, US',
        'sg' => 'Singapore, SG',
        'syd' => 'Sydney, AU',
        'br' => 'São Paulo, BR',
        'jh' => 'Johannesburg, ZA',
    ];

    public static function get(string $key): mixed
    {
        try {
            return Setting::get($key, self::DEFAULTS[$key] ?? null);
        } catch (\Throwable) {
            return self::DEFAULTS[$key] ?? null;
        }
    }

    public static function str(string $key): string
    {
        return trim((string) (self::get($key) ?? ''));
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
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        Setting::set($key, $value === null ? '' : (string) $value);
    }

    /** Decrypted secret or '' (never log or render it). */
    public static function secret(string $key): string
    {
        $enc = self::str($key);
        if ($enc === '') {
            return '';
        }
        try {
            return (string) Crypt::decryptString($enc);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function hasSecret(string $key): bool
    {
        return self::str($key) !== '';
    }

    public static function storeSecret(string $key, ?string $plain): void
    {
        Setting::set($key, $plain === null || $plain === '' ? '' : Crypt::encryptString($plain));
    }

    public static function ringSeconds(): int
    {
        return max(15, min(180, self::int('practice_call_ring_seconds')));
    }

    public static function maxRings(): int
    {
        return max(1, min(10, self::int('practice_call_max_rings')));
    }

    public static function recordingNotice(): string
    {
        return self::str('call_recording_notice') ?: self::DEFAULTS['call_recording_notice'];
    }
}
