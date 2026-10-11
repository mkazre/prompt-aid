<?php

namespace App\Services\Geo;

use App\Contracts\GeocodingInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real geocoding via OpenStreetMap Nominatim (free, no API key), plus
 * haversine distance / ETA maths.
 *
 * Nominatim usage policy (https://operations.osmfoundation.org/policies/nominatim/):
 *  - identify the app with a descriptive User-Agent,
 *  - max 1 request per second (enforced here with a shared cache lock),
 *  - cache results (24h per normalised query here).
 *
 * Every lookup degrades gracefully: on any network/HTTP failure search()
 * returns [] and geocode()/reverse() return null — callers must handle that.
 */
class NominatimGeocoder implements GeocodingInterface
{
    protected const EARTH_RADIUS_KM = 6371.0;

    protected const BASE_URL = 'https://nominatim.openstreetmap.org';

    protected const USER_AGENT = 'PromptAid/1.0 (https://promptaid.co.za)';

    protected const CACHE_TTL_SECONDS = 86400;

    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 2);
    }

    public function etaMinutes(float $distanceKm): int
    {
        // Assume an average urban travel speed of 30 km/h, minimum 5 minutes.
        return max(5, (int) ceil(($distanceKm / 30) * 60));
    }

    /**
     * Free-text address -> coordinates of the best (top) match, or null
     * when the address can't be found or Nominatim is unreachable.
     */
    public function geocode(string $address): ?array
    {
        // Same limit as autocomplete so a typed-then-submitted address hits the cache.
        $results = $this->search($address);

        if (empty($results)) {
            return null;
        }

        return ['lat' => $results[0]['lat'], 'lng' => $results[0]['lng']];
    }

    /**
     * Address suggestions for typeahead.
     *
     * @return array<int, array{label: string, full: string, lat: float, lng: float}>
     */
    public function search(string $query, int $limit = 6): array
    {
        $normalised = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));

        if (mb_strlen($normalised) < 3) {
            return [];
        }

        $cacheKey = 'geo:search:'.$limit.':'.md5($normalised);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->request('/search', [
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => $limit,
            'countrycodes' => 'za',
            'q' => $normalised,
        ]);

        if ($rows === null) {
            return []; // failure: don't cache, so a later retry can succeed
        }

        // OSM often lacks house numbers; keep the one the patient typed so the
        // driver still gets "45 Rivonia Road" rather than just the street.
        $typedNumber = preg_match('/^(\d+[a-z]?)\s+\D/i', $normalised, $m) ? strtoupper($m[1]) : null;

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['lat'], $row['lon'])) {
                continue;
            }
            if ($typedNumber && ! empty($row['address']['road']) && empty($row['address']['house_number'])) {
                $row['address']['house_number'] = $typedNumber;
            }
            $results[] = [
                'label' => $this->shortLabel($row),
                'full' => (string) ($row['display_name'] ?? ''),
                'lat' => round((float) $row['lat'], 7),
                'lng' => round((float) $row['lon'], 7),
            ];
        }

        Cache::put($cacheKey, $results, self::CACHE_TTL_SECONDS);

        return $results;
    }

    /**
     * Coordinates -> human-readable address (for "use my current location").
     *
     * @return array{label: string, full: string, lat: float, lng: float}|null
     */
    public function reverse(float $lat, float $lng): ?array
    {
        // ~11m precision is plenty for a pickup point and keeps the cache useful.
        $lat = round($lat, 4);
        $lng = round($lng, 4);
        $cacheKey = 'geo:reverse:'.$lat.','.$lng;

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $row = $this->request('/reverse', [
            'format' => 'json',
            'addressdetails' => 1,
            'zoom' => 18,
            'lat' => $lat,
            'lon' => $lng,
        ]);

        if (! is_array($row) || ! isset($row['lat'], $row['lon']) || isset($row['error'])) {
            return null;
        }

        $result = [
            'label' => $this->shortLabel($row),
            'full' => (string) ($row['display_name'] ?? ''),
            'lat' => $lat,
            'lng' => $lng,
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * Throttled GET against Nominatim. Returns decoded JSON or null on failure.
     */
    protected function request(string $path, array $query): ?array
    {
        try {
            return $this->throttled(function () use ($path, $query) {
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'en',
                ])
                    ->timeout(8)
                    ->connectTimeout(4)
                    ->get(self::BASE_URL.$path, $query);

                if (! $response->successful()) {
                    Log::warning('Nominatim request failed', ['path' => $path, 'status' => $response->status()]);

                    return null;
                }

                $json = $response->json();

                return is_array($json) ? $json : null;
            });
        } catch (Throwable $e) {
            Log::warning('Nominatim request error', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Enforce Nominatim's 1 request/second policy across all PHP workers by
     * serialising calls through a cache lock and spacing them >= 1s apart.
     */
    protected function throttled(callable $callback)
    {
        try {
            $lock = Cache::lock('geo:nominatim:lock', 10);
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            // Could not obtain the lock in time (heavy load) — skip rather than
            // risk breaching the usage policy; callers handle the null.
            Log::info('Nominatim throttle lock timeout');

            return null;
        } catch (Throwable $e) {
            // Cache store can't do locks (misconfigured store) — still serve the
            // request rather than break geocoding entirely.
            Log::warning('Nominatim throttle lock unavailable', ['error' => $e->getMessage()]);

            return $callback();
        }

        try {
            $last = (float) Cache::get('geo:nominatim:last', 0);
            $wait = 1.0 - (microtime(true) - $last);
            if ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000));
            }

            try {
                return $callback();
            } finally {
                Cache::put('geo:nominatim:last', microtime(true), 60);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * A compact, readable label from Nominatim's address parts, e.g.
     * "Sandton City, Pybus Avenue, Sandton, 1969". Falls back to display_name.
     */
    protected function shortLabel(array $row): string
    {
        $a = $row['address'] ?? [];
        $parts = [];

        $name = $row['name'] ?? null;
        $street = trim(($a['house_number'] ?? '').' '.($a['road'] ?? ''));

        if ($name && $name !== ($a['road'] ?? null)) {
            $parts[] = $name;
        }
        if ($street !== '') {
            $parts[] = $street;
        }

        $suburb = $a['suburb'] ?? $a['neighbourhood'] ?? $a['quarter'] ?? null;
        if ($suburb && ! str_contains($suburb, 'Ward')) {
            $parts[] = $suburb;
        }

        $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? null;
        if ($city) {
            $parts[] = $city;
        }

        if (! empty($a['postcode'])) {
            $parts[] = $a['postcode'];
        }

        $parts = array_values(array_unique(array_filter($parts)));

        if (count($parts) < 2) {
            return (string) ($row['display_name'] ?? implode(', ', $parts));
        }

        return implode(', ', $parts);
    }
}
