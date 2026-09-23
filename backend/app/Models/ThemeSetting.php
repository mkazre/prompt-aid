<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ThemeSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("theme_setting.{$key}", fn () => static::query()->where('key', $key)->value('value') ?? $default);
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("theme_setting.{$key}");
    }

    public static function flush(): void
    {
        foreach (static::query()->pluck('key') as $key) {
            Cache::forget("theme_setting.{$key}");
        }
    }
}
