<?php

namespace App\Services\Geo;

use App\Support\ShuttleSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Road routing (distance, duration, geometry) via an OSRM server.
 *
 * Base URL is admin-configurable (Shuttle settings), defaulting to the
 * public demo server router.project-osrm.org, whose usage policy is "max
 * 1 request/second, no heavy use". So:
 *   - every route is cached (coordinates rounded to ~11 m; 6 h TTL),
 *   - calls are spaced >= 1 s apart platform-wide (shared cache key),
 *   - a failure opens a 60 s circuit breaker, during which (and whenever
 *     OSRM is disabled/unreachable) we fall back to straight-line distance
 *     x road factor (default 1.3) at the fallback speed (default 30 km/h)
 *     with a 2-point straight geometry.
 * Callers never call this per-poll without their own throttle (see
 * RideLocationService::refreshEta, 30 s per ride).
 */
class RoutingService
{
    public const CACHE_TTL_SECONDS = 21600;

    protected const USER_AGENT = 'PromptAid/1.0 (+https://promptaid.health)';

    /**
     * @return array{distance_km: float, duration_min: int, geometry: array<int, array{0: float, 1: float}>, source: string}
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, int $precision = 4): array
    {
        $key = sprintf('route:v1:%s,%s;%s,%s', round($fromLat, $precision), round($fromLng, $precision), round($toLat, $precision), round($toLng, $precision));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->osrm($fromLat, $fromLng, $toLat, $toLng);

        if ($result === null) {
            // Short cache for fallbacks so we retry OSRM soon.
            $result = $this->fallback($fromLat, $fromLng, $toLat, $toLng);
            Cache::put($key, $result, 120);

            return $result;
        }

        Cache::put($key, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * Straight-line x road factor at the fallback speed — used when OSRM is
     * off/down, and by bulk estimates (e.g. emergency facility list) that
     * must not fan out into many routing calls.
     *
     * @return array{distance_km: float, duration_min: int, geometry: array<int, array{0: float, 1: float}>, source: string}
     */
    public function fallback(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $km = self::haversineKm($fromLat, $fromLng, $toLat, $toLng) * max(1.0, ShuttleSettings::float('shuttle_fallback_road_factor'));
        $speed = max(5.0, ShuttleSettings::float('shuttle_fallback_speed_kmh'));

        return [
            'distance_km' => round($km, 2),
            'duration_min' => max(1, (int) ceil(($km / $speed) * 60)),
            'geometry' => [[round($fromLat, 6), round($fromLng, 6)], [round($toLat, 6), round($toLng, 6)]],
            'source' => 'estimate',
        ];
    }

    protected function osrm(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        if (! ShuttleSettings::bool('shuttle_routing_enabled') || Cache::get('route:osrm:down')) {
            return null;
        }

        $base = rtrim(ShuttleSettings::str('shuttle_osrm_base_url'), '/');
        if ($base === '' || ! preg_match('#^https?://#i', $base)) {
            return null;
        }

        // Same-point request: nothing to route.
        if (abs($fromLat - $toLat) < 0.00001 && abs($fromLng - $toLng) < 0.00001) {
            return ['distance_km' => 0.0, 'duration_min' => 0, 'geometry' => [[$fromLat, $fromLng]], 'source' => 'osrm'];
        }

        $this->spaceRequests();

        try {
            $url = sprintf('%s/route/v1/driving/%F,%F;%F,%F', $base, $fromLng, $fromLat, $toLng, $toLat);
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(5)
                ->connectTimeout(3)
                ->get($url, ['overview' => 'full', 'geometries' => 'geojson', 'alternatives' => 'false', 'steps' => 'false']);

            $json = $response->json();
            if (! $response->successful() || ($json['code'] ?? null) !== 'Ok' || empty($json['routes'][0])) {
                // "NoRoute" etc. is a valid answer for that pair (e.g. island) — don't trip the breaker for it.
                if (! $response->successful()) {
                    $this->tripBreaker('HTTP '.$response->status());
                }

                return null;
            }

            $route = $json['routes'][0];
            $coords = $route['geometry']['coordinates'] ?? [];
            $geometry = [];
            foreach ($coords as $c) {
                $geometry[] = [round((float) $c[1], 5), round((float) $c[0], 5)];
            }

            return [
                'distance_km' => round(((float) $route['distance']) / 1000, 2),
                'duration_min' => max(1, (int) ceil(((float) $route['duration']) / 60)),
                'geometry' => $geometry ?: [[$fromLat, $fromLng], [$toLat, $toLng]],
                'source' => 'osrm',
            ];
        } catch (\Throwable $e) {
            $this->tripBreaker($e->getMessage());

            return null;
        }
    }

    /** Keep >= 1 s between OSRM calls across all PHP workers (demo-server policy). */
    protected function spaceRequests(): void
    {
        try {
            $last = (float) Cache::get('route:osrm:last', 0);
            $wait = 1.0 - (microtime(true) - $last);
            if ($wait > 0) {
                usleep((int) min(1_000_000, $wait * 1_000_000));
            }
            Cache::put('route:osrm:last', microtime(true), 60);
        } catch (\Throwable) {
            // Cache hiccup: proceed without spacing.
        }
    }

    protected function tripBreaker(string $why): void
    {
        Log::warning('[RoutingService] OSRM unavailable, falling back for 60s: '.$why);
        Cache::put('route:osrm:down', true, 60);
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Initial compass bearing (0 = north) from point A to point B. */
    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lng2 - $lng1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Walk $km along a polyline; returns the point and the heading of the
     * segment it lands on, plus whether the end was reached.
     *
     * @param  array<int, array{0: float, 1: float}>  $geometry
     * @return array{lat: float, lng: float, heading: float, done: bool}
     */
    public static function pointAlong(array $geometry, float $km): array
    {
        $n = count($geometry);
        if ($n === 0) {
            return ['lat' => 0.0, 'lng' => 0.0, 'heading' => 0.0, 'done' => true];
        }
        if ($n === 1 || $km <= 0) {
            $h = $n > 1 ? self::bearing($geometry[0][0], $geometry[0][1], $geometry[1][0], $geometry[1][1]) : 0.0;

            return ['lat' => (float) $geometry[0][0], 'lng' => (float) $geometry[0][1], 'heading' => $h, 'done' => $n === 1];
        }

        $remaining = $km;
        for ($i = 0; $i < $n - 1; $i++) {
            [$aLat, $aLng] = $geometry[$i];
            [$bLat, $bLng] = $geometry[$i + 1];
            $seg = self::haversineKm($aLat, $aLng, $bLat, $bLng);
            if ($seg <= 0) {
                continue;
            }
            if ($remaining <= $seg) {
                $t = $remaining / $seg;

                return [
                    'lat' => $aLat + ($bLat - $aLat) * $t,
                    'lng' => $aLng + ($bLng - $aLng) * $t,
                    'heading' => self::bearing($aLat, $aLng, $bLat, $bLng),
                    'done' => false,
                ];
            }
            $remaining -= $seg;
        }

        $last = $geometry[$n - 1];
        $prev = $geometry[$n - 2];

        return ['lat' => (float) $last[0], 'lng' => (float) $last[1], 'heading' => self::bearing($prev[0], $prev[1], $last[0], $last[1]), 'done' => true];
    }

    /** @param  array<int, array{0: float, 1: float}>  $geometry */
    public static function lengthKm(array $geometry): float
    {
        $sum = 0.0;
        for ($i = 0, $n = count($geometry); $i < $n - 1; $i++) {
            $sum += self::haversineKm($geometry[$i][0], $geometry[$i][1], $geometry[$i + 1][0], $geometry[$i + 1][1]);
        }

        return $sum;
    }
}
