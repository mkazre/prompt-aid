<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Map tile provider used by every Leaflet map (website, admin panels, app WebViews).
 * Tile keys are sent to browsers/phones by design, so restrict the key to the
 * promptaid.co.za domain / app in the provider dashboard rather than treating it as a secret.
 */
class MapSettings
{
    public const PRESETS = [
        'carto' => [
            'label' => 'CARTO',
            'template' => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png?api_key={key}',
            'subdomains' => 'abcd',
            'attribution' => '&copy; OpenStreetMap contributors &copy; CARTO',
            'max_zoom' => 20,
        ],
        'maptiler' => [
            'label' => 'MapTiler',
            'template' => 'https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key={key}',
            'subdomains' => '',
            'attribution' => '&copy; MapTiler &copy; OpenStreetMap contributors',
            'max_zoom' => 20,
        ],
        'stadia' => [
            'label' => 'Stadia Maps',
            'template' => 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}{r}.png?api_key={key}',
            'subdomains' => '',
            'attribution' => '&copy; Stadia Maps &copy; OpenStreetMap contributors',
            'max_zoom' => 20,
        ],
        'osm' => [
            'label' => 'OpenStreetMap (no key, low volume only)',
            'template' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'subdomains' => '',
            'attribution' => '&copy; OpenStreetMap contributors',
            'max_zoom' => 19,
        ],
    ];

    public static function provider(): string
    {
        $p = (string) Setting::get('map_provider', 'carto');

        return $p === 'custom' || isset(self::PRESETS[$p]) ? $p : 'carto';
    }

    public static function key(): string
    {
        return trim((string) Setting::get('map_api_key', ''));
    }

    public static function template(): string
    {
        $custom = trim((string) Setting::get('map_tile_template', ''));

        return $custom !== '' ? $custom : (self::PRESETS[self::provider()]['template'] ?? self::PRESETS['osm']['template']);
    }

    /** Leaflet-ready config shared with the website, panels and the app. */
    public static function clientConfig(): array
    {
        $preset = self::PRESETS[self::provider()] ?? [];
        $url = str_replace('{key}', rawurlencode(self::key()), self::template());
        $subdomains = (string) (Setting::get('map_subdomains') ?: ($preset['subdomains'] ?? ''));
        if ($subdomains === '' && str_contains($url, '{s}')) {
            $subdomains = 'abc';
        }

        return [
            'url' => $url,
            'subdomains' => $subdomains,
            'attribution' => (string) (Setting::get('map_attribution') ?: ($preset['attribution'] ?? '&copy; OpenStreetMap contributors')),
            'maxZoom' => (int) (Setting::get('map_max_zoom') ?: ($preset['max_zoom'] ?? 19)),
            'fallbackUrl' => self::PRESETS['osm']['template'],
        ];
    }
}
