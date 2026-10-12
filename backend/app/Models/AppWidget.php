<?php

namespace App\Models;

use App\Support\Widgets\WidgetDestinations;
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
            // Feature switches (Staff → System → Features) hide widgets for switched-off services.
            ->filter(fn (self $w) => \App\Support\Features::allowsWidget((string) $w->key, (array) ($w->settings ?? [])))
            ->values();
    }

    /** @return Collection<int, AppWidget> enabled widgets for one role across every screen */
    public static function forRole(string $role): Collection
    {
        return static::enabledOrdered()
            ->filter(fn (self $w) => $w->role === $role)
            ->filter(fn (self $w) => \App\Support\Features::allowsWidget((string) $w->key, (array) ($w->settings ?? [])))
            ->values();
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
        $settings = array_filter($this->settings ?? [], fn ($v) => $v !== null && $v !== '');

        // Destination picked in the admin → app target + website link.
        if (! empty($settings['destination'])) {
            $to = WidgetDestinations::resolve($settings);
            $settings['cta_target'] = $to['app'] ?: ($settings['cta_target'] ?? null);
            $settings['web_url'] = $to['web'];
            if ($to['group']) {
                $settings['cta_params'] = ['group' => $to['group']];
            }
        }

        if (! empty($settings['icon_image'])) {
            $settings['icon_image_url'] = self::mediaUrl($settings['icon_image']);
        }

        // Shortcut tiles (search widget): defaults until an admin edits them.
        if (str_contains($this->key, 'shortcuts')) {
            $tiles = ! empty($settings['shortcuts']) && is_array($settings['shortcuts']) ? $settings['shortcuts'] : WidgetDestinations::DEFAULT_SHORTCUTS;
            // Feature switches: drop tiles that lead to a switched-off service.
            $tiles = array_filter($tiles, fn ($tile) => is_array($tile) && \App\Support\Features::allowsWidget('', $tile));
            $settings['shortcuts'] = array_values(array_map(function (array $tile) {
                $to = WidgetDestinations::resolve($tile);

                return [
                    'label' => (string) ($tile['label'] ?? ''),
                    'icon' => $tile['icon'] ?? 'grid',
                    'icon_image_url' => ! empty($tile['icon_image']) ? self::mediaUrl($tile['icon_image']) : null,
                    'color' => $tile['color'] ?? null,
                    'target' => $to['app'],
                    'params' => $to['group'] ? ['group' => $to['group']] : null,
                    'web_url' => $to['web'],
                ];
            }, $tiles));
        }

        if ($this->image) {
            $settings['image_url'] = str_starts_with($this->image, 'http')
                ? $this->image
                : Storage::disk('public')->url($this->image);
        }

        return $settings;
    }

    protected static function mediaUrl(string $path): string
    {
        return preg_match('#^(https?:)?//#', $path) ? $path : Storage::disk('public')->url($path);
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
