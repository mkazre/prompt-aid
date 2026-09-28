<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-configurable replacement for what used to be hardcoded in
 * public/assets/js/triage.js — the pre-triage symptom list, red-flag
 * discriminators, TEWS-lite scoring weights, per-level display metadata and
 * facility-capability map. One row per section, mirroring the Setting /
 * ThemeSetting key-value pattern. Served to the public triage page as JSON
 * via TriageConfigController; the scoring logic itself stays in JS.
 */
class TriageConfig extends Model
{
    public const KEY_SYMPTOMS = 'symptoms';

    public const KEY_DISCRIMINATORS = 'discriminators';

    public const KEY_WEIGHTS = 'weights';

    public const KEY_META = 'meta';

    public const KEY_CAPABILITY = 'capability';

    public const KEYS = [
        self::KEY_SYMPTOMS,
        self::KEY_DISCRIMINATORS,
        self::KEY_WEIGHTS,
        self::KEY_META,
        self::KEY_CAPABILITY,
    ];

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('triage-config'));
        static::deleted(fn () => Cache::forget('triage-config'));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * @return array{symptoms: array, discriminators: array, weights: array, meta: array, capability: array}
     */
    public static function current(): array
    {
        return Cache::remember('triage-config', now()->addHour(), function () {
            $rows = static::query()->whereIn('key', self::KEYS)->pluck('value', 'key');

            return [
                'symptoms' => $rows[self::KEY_SYMPTOMS] ?? [],
                'discriminators' => $rows[self::KEY_DISCRIMINATORS] ?? [],
                'weights' => $rows[self::KEY_WEIGHTS] ?? [],
                'meta' => $rows[self::KEY_META] ?? [],
                'capability' => $rows[self::KEY_CAPABILITY] ?? [],
            ];
        });
    }
}
