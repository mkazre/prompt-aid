<?php

namespace App\Services\Geo;

use App\Contracts\GeocodingInterface;

/**
 * Real-math distance/ETA (haversine formula) with a stubbed geocode() that
 * returns a deterministic pseudo-location for an address. Swap the binding
 * in AppServiceProvider for Google Maps / Mapbox later without touching
 * any caller — RideService, controllers, etc. all depend on the interface.
 */
class HaversineGeocoder implements GeocodingInterface
{
    protected const EARTH_RADIUS_KM = 6371.0;

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

    public function geocode(string $address): ?array
    {
        // Deterministic pseudo-geocode around Johannesburg for demo purposes,
        // seeded from the address string so the same input is stable.
        $seed = crc32($address);
        mt_srand($seed);

        return [
            'lat' => round(-26.1076 + (mt_rand(-800, 800) / 10000), 6),
            'lng' => round(28.0567 + (mt_rand(-800, 800) / 10000), 6),
        ];
    }
}
