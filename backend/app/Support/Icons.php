<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The Feather icon library (287 icons, MIT) — the same set the app draws
 * with @expo/vector-icons' Feather, so an icon picked in the admin looks
 * identical on the website and in the app. Source: feather-icons@4.29.2
 * dist/icons.json, stored in resources/data/feather-icons.json.
 */
class Icons
{
    /** @var array<string, string>|null name => inner SVG markup */
    protected static ?array $icons = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        return static::$icons ??= json_decode((string) file_get_contents(resource_path('data/feather-icons.json')), true) ?: [];
    }

    public static function exists(?string $name): bool
    {
        return $name !== null && isset(static::all()[$name]);
    }

    public static function svg(?string $name, int $size = 20, string $class = 'paw-ico'): HtmlString
    {
        $inner = static::all()[$name ?? ''] ?? static::all()['grid'] ?? '';

        return new HtmlString('<svg class="'.e($class).'" viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$inner.'</svg>');
    }

    /**
     * Options for a searchable Filament Select with a preview of each icon
     * (use with ->allowHtml()).
     *
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        $options = [];
        foreach (array_keys(static::all()) as $name) {
            $options[$name] = '<span style="display:inline-flex;align-items:center;gap:8px">'.static::svg($name, 18, 'pa-icon-opt').'<span>'.e($name).'</span></span>';
        }

        return $options;
    }
}
