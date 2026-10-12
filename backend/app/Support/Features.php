<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Platform feature switches, edited by super admins at /staff → System →
 * Features (App\Filament\Pages\FeatureSettings). Each switch is a Setting
 * row `feature_{key}` ("1"/"0"); a missing row means ON, so nothing changes
 * for a site that never opened the page.
 *
 * When a feature is OFF:
 *  - the website hides its nav links and home sections (Blade checks
 *    Features::on()) and its entry routes answer with a friendly
 *    "not available" page (FeatureGate middleware, matched by route name
 *    below — no route definitions change, so route:cache is unaffected);
 *  - the app hides its tabs, home tiles and widgets (GET /api/site-content
 *    → `features`, and GET /api/features);
 *  - the API refuses to START new work for it (new orders, rides, on-demand
 *    requests). Read endpoints and in-flight work (tracking a ride already
 *    booked, an order already placed) keep working.
 */
class Features
{
    /** @var array<string, array{label: string, help: string}> */
    public const FEATURES = [
        'shop' => ['label' => 'Pharmacy shop', 'help' => 'Online pharmacy, products, checkout and script upload.'],
        'shuttle' => ['label' => 'Shuttle', 'help' => 'Medical shuttle quotes and new ride bookings. Rides already booked can still be tracked.'],
        'video' => ['label' => 'Video consults', 'help' => 'The "Virtual consult" pages and tabs.'],
        'doctor_now' => ['label' => 'See a doctor now', 'help' => 'On-demand "see the next available doctor" requests.'],
        'testimonials' => ['label' => 'Testimonials', 'help' => 'The public "Submit a testimonial" form and its links.'],
        'articles' => ['label' => 'Health articles', 'help' => 'The health articles library on the website and app.'],
        'topic' => ['label' => 'Topic of the month', 'help' => 'The Topic of the month pages and home banner.'],
        'labs' => ['label' => 'Lab / Radiology pages', 'help' => 'The public laboratory and radiology directory pages.'],
    ];

    /**
     * Web route names (exact, or prefix ending in "*") that belong to each
     * feature. Only ENTRY points are listed: e.g. a booked ride's tracking
     * page stays reachable while the shuttle is switched off.
     *
     * @var array<string, array<int, string>>
     */
    public const WEB_ROUTES = [
        'shop' => ['shop.*', 'pharmacies.index', 'pharmacies.show', 'pharmacies.checkout'],
        'shuttle' => ['shuttle', 'shuttle.quote', 'rides.store', 'rides.return'],
        'video' => ['virtual-consult'],
        'doctor_now' => ['instant-consult', 'account.doctor-now', 'account.doctor-now.quote', 'account.doctor-now.store'],
        'testimonials' => ['testimonials.create', 'testimonials.store'],
        'articles' => ['health-articles.*'],
        'topic' => ['topic.show', 'topic.view'],
        'labs' => ['radiology.index', 'laboratories.index', 'labs.index', 'labs.show'],
    ];

    /**
     * API "start new work" endpoints per feature: [METHOD, path pattern
     * relative to the site root as Request::is() expects].
     *
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    public const API_ROUTES = [
        'shop' => [['POST', 'api/orders/checkout'], ['POST', 'api/prescriptions']],
        'shuttle' => [['POST', 'api/rides'], ['POST', 'api/rides/quote'], ['POST', 'api/rides/quote-by-address'], ['POST', 'api/ride-series'], ['POST', 'api/rides/*/return']],
        'doctor_now' => [['POST', 'api/request-doctor']],
    ];

    /**
     * App widget keys (Site → App widgets) hidden while a feature is off,
     * plus app tab names a widget's CTA may target.
     *
     * @var array<string, array{widgets: array<int, string>, tabs: array<int, string>}>
     */
    public const APP = [
        'shop' => ['widgets' => ['shop_categories', 'shop_featured_products', 'patient_recent_orders'], 'tabs' => ['Pharmacy']],
        'shuttle' => ['widgets' => ['patient_upcoming_ride'], 'tabs' => ['Rides']],
        'articles' => ['widgets' => ['patient_health_articles'], 'tabs' => ['HealthArticles']],
        'doctor_now' => ['widgets' => [], 'tabs' => ['InstantConsult']],
    ];

    public static function key(string $feature): string
    {
        return 'feature_'.$feature;
    }

    public static function on(string $feature): bool
    {
        if (! array_key_exists($feature, self::FEATURES)) {
            return true;
        }

        try {
            $value = Setting::get(self::key($feature), '1');
        } catch (\Throwable $e) {
            return true; // DB not ready (fresh install) — never hide things on an error.
        }

        return ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no', ''], true);
    }

    public static function off(string $feature): bool
    {
        return ! static::on($feature);
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $feature) {
            $out[$feature] = static::on($feature);
        }

        return $out;
    }

    public static function set(string $feature, bool $on): void
    {
        if (array_key_exists($feature, self::FEATURES)) {
            Setting::set(self::key($feature), $on ? '1' : '0');
        }
    }

    /** The feature a named web route belongs to, if any. */
    public static function forRouteName(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        foreach (self::WEB_ROUTES as $feature => $patterns) {
            foreach ($patterns as $pattern) {
                if ($pattern === $name || (str_ends_with($pattern, '*') && str_starts_with($name, substr($pattern, 0, -1)))) {
                    return $feature;
                }
            }
        }

        return null;
    }

    /** The feature an API request starts new work for, if any. */
    public static function forApiRequest(Request $request): ?string
    {
        foreach (self::API_ROUTES as $feature => $routes) {
            foreach ($routes as [$method, $pattern]) {
                if ($request->isMethod($method) && $request->is($pattern)) {
                    return $feature;
                }
            }
        }

        return null;
    }

    /**
     * Whether a menu link (route name or URL) should show. Links to a
     * switched-off feature are hidden.
     */
    public static function allowsLink(?string $routeName, ?string $url = null): bool
    {
        $feature = static::forRouteName($routeName);
        if ($feature === null && $url) {
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $feature = match (true) {
                $path === 'shop' || str_starts_with($path, 'shop/') || $path === 'pharmacies' || str_starts_with($path, 'pharmacies/') => 'shop',
                $path === 'shuttle' => 'shuttle',
                $path === 'virtual-consult' => 'video',
                $path === 'see-a-doctor-now' || str_starts_with($path, 'account/see-a-doctor') => 'doctor_now',
                str_starts_with($path, 'testimonials') => 'testimonials',
                str_starts_with($path, 'health-articles') => 'articles',
                $path === 'topic-of-the-month' || str_starts_with($path, 'topics/') => 'topic',
                in_array($path, ['radiology', 'laboratories', 'labs'], true) || str_starts_with($path, 'labs/') => 'labs',
                default => null,
            };
        }

        return $feature === null || static::on($feature);
    }

    /**
     * Widget "destination" keys (App\Support\Widgets\WidgetDestinations) that
     * belong to a feature.
     *
     * @var array<string, string>
     */
    public const DESTINATIONS = [
        'virtual' => 'video',
        'doctor_now' => 'doctor_now',
        'pharmacy' => 'shop',
        'script_upload' => 'shop',
        'radiology' => 'labs',
        'laboratories' => 'labs',
        'shuttle' => 'shuttle',
        'health_articles' => 'articles',
        'topic' => 'topic',
        'testimonial' => 'testimonials',
    ];

    /** Whether an app widget (key + settings) — or one shortcut tile (key '') — may be shown. */
    public static function allowsWidget(string $key, array $settings = []): bool
    {
        $destination = (string) ($settings['destination'] ?? '');
        if ($destination !== '' && isset(self::DESTINATIONS[$destination]) && static::off(self::DESTINATIONS[$destination])) {
            return false;
        }

        $target = (string) ($settings['cta_target'] ?? $settings['target'] ?? '');
        $tab = $target !== '' ? explode('/', $target)[0] : '';
        $screen = str_contains($target, '/') ? explode('/', $target)[1] : '';

        if (! empty($settings['web_url']) && is_string($settings['web_url']) && ! static::allowsLink(null, $settings['web_url'])) {
            return false;
        }

        foreach (self::APP as $feature => $map) {
            if (static::on($feature)) {
                continue;
            }
            if (in_array($key, $map['widgets'], true) || ($tab !== '' && in_array($tab, $map['tabs'], true)) || ($screen !== '' && in_array($screen, $map['tabs'], true))) {
                return false;
            }
        }

        return true;
    }
}
