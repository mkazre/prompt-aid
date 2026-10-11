<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * One admin-configurable home/screen widget for the mobile app and the
 * patient web dashboard. Rows are seeded from
 * App\Support\Widgets\WidgetCatalog; admins toggle, reorder, retitle and
 * tweak `settings` (limit, accent, CTA, banner copy…) in Site → App Widgets.
 * What a widget *shows* is always computed from real data by
 * App\Support\Widgets\WidgetDataService, scoped to the viewing user.
 */
class AppWidget extends Model
{
    protected const CACHE_KEY = 'app-widgets';

    protected $fillable = [
        'key', 'title', 'subtitle', 'role', 'screen', 'is_enabled', 'sort_order', 'settings', 'image',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** @return Collection<int, AppWidget> every enabled widget, ordered */
    public static function enabledOrdered(): Collection
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHour(),
            fn () => static::query()->where('is_enabled', true)->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    /** @return Collection<int, AppWidget> enabled widgets for one role + screen, ordered */
    public static function for(string $role, string $screen): Collection
    {
        return static::enabledOrdered()
            ->filter(fn (self $w) => $w->role === $role && $w->screen === $screen)
            ->values();
    }

    /** @return Collection<int, AppWidget> enabled widgets for one role across every screen */
    public static function forRole(string $role): Collection
    {
        return static::enabledOrdered()->filter(fn (self $w) => $w->role === $role)->values();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $value = ($this->settings ?? [])[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public function intSetting(string $key, int $default, int $min = 1, int $max = 50): int
    {
        $value = (int) $this->setting($key, $default);

        return max($min, min($max, $value ?: $default));
    }

    /**
     * Settings as sent to clients — the uploaded image (if any) resolved to
     * a public URL under `image_url`, overriding a typed-in URL.
     *
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $settings = $this->settings ?? [];

        if ($this->image) {
            $settings['image_url'] = str_starts_with($this->image, 'http')
                ? $this->image
                : Storage::disk('public')->url($this->image);
        }

        return $settings;
    }

    /** @return array{key: string, title: string, subtitle: ?string, settings: array<string, mixed>} */
    public function toClientArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'settings' => (object) $this->publicSettings(),
        ];
    }
}
