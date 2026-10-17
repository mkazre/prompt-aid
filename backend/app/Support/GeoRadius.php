<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Patient "search within X km" for listings (doctors, clinics, pharmacies),
 * website and API. Query params: lat, lng, radius_km (optional; falls back
 * to the patient's saved preference, then the admin default).
 */
class GeoRadius
{
    /** @return array{lat: float, lng: float, km: float}|null */
    public static function fromRequest(Request $request): ?array
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return null;
        }
        $km = $request->query('radius_km');
        if (! is_numeric($km)) {
            $km = $request->user()?->patientProfile?->search_radius_km;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng, 'km' => DispatchSettings::clampRadius($km)];
    }

    /** Cheap indexed pre-filter (bounding box); refine with km() afterwards. */
    public static function box(Builder $query, string $latCol, string $lngCol, array $geo): Builder
    {
        $dLat = $geo['km'] / 111.0;
        $dLng = $geo['km'] / max(1.0, 111.0 * cos(deg2rad($geo['lat'])));

        return $query->whereNotNull($latCol)->whereNotNull($lngCol)
            ->whereBetween($latCol, [$geo['lat'] - $dLat, $geo['lat'] + $dLat])
            ->whereBetween($lngCol, [$geo['lng'] - $dLng, $geo['lng'] + $dLng]);
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
