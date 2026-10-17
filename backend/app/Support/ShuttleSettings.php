<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Single registry of every admin-configurable shuttle (ride) setting, with
 * its default. Values live as key/value rows in `settings` (Setting model,
 * cached forever and invalidated by Setting::set), edited from
 * Staff → Ride Service → Shuttle settings. Defaults here are identical to
 * what shuttle_v2_migration.sql seeds, so a missing row behaves exactly
 * like a freshly seeded one.
 *
 * JSON-valued settings are stored as JSON strings.
 */
class ShuttleSettings
{
    public const DEFAULTS = [
        // --- Routing / tracking ---
        'shuttle_osrm_base_url' => 'https://router.project-osrm.org',
        'shuttle_routing_enabled' => '1',
        'shuttle_fallback_road_factor' => '1.3',
        'shuttle_fallback_speed_kmh' => '30',
        'shuttle_eta_refresh_seconds' => '30',
        'shuttle_location_min_interval_seconds' => '2',
        'shuttle_driver_stale_minutes' => '10',
        'shuttle_pricing_timezone' => 'Africa/Johannesburg',

        // --- Pricing ---
        'shuttle_booking_fee' => '5',
        'shuttle_quote_ttl_minutes' => '10',
        'shuttle_time_bands' => '[{"label":"Morning peak","days":[1,2,3,4,5],"start":"06:00","end":"09:00","multiplier":1.3},{"label":"Evening peak","days":[1,2,3,4,5],"start":"16:00","end":"19:00","multiplier":1.3},{"label":"Night","days":[0,1,2,3,4,5,6],"start":"21:00","end":"05:00","multiplier":1.2}]',
        'shuttle_public_holidays' => '[]',
        'shuttle_holiday_multiplier' => '1.25',
        'shuttle_surge_enabled' => '1',
        'shuttle_surge_radius_km' => '5',
        'shuttle_surge_window_minutes' => '15',
        'shuttle_surge_steps' => '[{"ratio":1.5,"multiplier":1.2},{"ratio":2,"multiplier":1.5},{"ratio":3,"multiplier":1.8}]',
        'shuttle_max_multiplier' => '2.5',
        'shuttle_free_wait_minutes' => '5',
        'shuttle_wait_rate_per_minute' => '2',
        'shuttle_route_change_threshold_pct' => '20',
        'shuttle_charge_fare_on_completion' => '1',

        // --- Cancellation / no-show ---
        'shuttle_free_cancel_minutes' => '2',
        'shuttle_cancel_fee_eta_minutes' => '5',
        'shuttle_cancel_fee_type' => 'fixed',
        'shuttle_cancel_fee_amount' => '25',
        'shuttle_cancel_fee_percent' => '10',
        'shuttle_cancel_fee_overrides' => '[]',
        'shuttle_no_show_wait_minutes' => '5',
        'shuttle_no_show_fee_type' => 'fixed',
        'shuttle_no_show_fee_amount' => '40',
        'shuttle_no_show_fee_percent' => '20',
        'shuttle_cancel_reasons_patient' => '["Driver is taking too long","Booked by mistake","Changed my plans","Found another way to get there","Wrong pickup location","Other"]',
        'shuttle_cancel_reasons_driver' => '["Patient asked me to cancel","Vehicle problem","Cannot reach pickup location","Safety concern","Other"]',

        // --- Dispatch ---
        'shuttle_offer_timeout_seconds' => '20',
        'shuttle_dispatch_max_attempts' => '6',
        'shuttle_dispatch_radius_km' => '8',
        'shuttle_dispatch_max_radius_km' => '25',
        'shuttle_dispatch_timeout_minutes' => '5',
        'shuttle_trip_pin_mode' => 'all', // the driver app now collects the PIN; existing installs keep their saved value (Dispatch settings)
        'shuttle_scheduled_dispatch_lead_minutes' => '20',

        // --- Data retention (POPIA) ---
        'shuttle_location_retention_days' => '90',

        // --- Test simulator ---
        'shuttle_simulator_speed_kmh' => '45',
    ];

    public static function get(string $key): mixed
    {
        return Setting::get($key, self::DEFAULTS[$key] ?? null);
    }

    public static function str(string $key): string
    {
        return trim((string) self::get($key));
    }

    public static function float(string $key): float
    {
        $v = self::get($key);

        return is_numeric($v) ? (float) $v : (float) (self::DEFAULTS[$key] ?? 0);
    }

    public static function int(string $key): int
    {
        return (int) round(self::float($key));
    }

    public static function bool(string $key): bool
    {
        $v = self::get($key);

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<int|string, mixed> */
    public static function json(string $key): array
    {
        $v = self::get($key);
        if (is_array($v)) {
            return $v;
        }
        $decoded = json_decode((string) $v, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $fallback = json_decode((string) (self::DEFAULTS[$key] ?? '[]'), true);

        return is_array($fallback) ? $fallback : [];
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_array($value)) {
            $value = json_encode(array_values($value));
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        Setting::set($key, $value === null ? null : (string) $value);
    }

    /** "all" (default — web console and driver app both ask for the PIN), "web_only" (older app builds), or "off". */
    public static function pinRequired(bool $fromApi): bool
    {
        return match (self::str('shuttle_trip_pin_mode')) {
            'all' => true,
            'off' => false,
            default => ! $fromApi,
        };
    }
}
