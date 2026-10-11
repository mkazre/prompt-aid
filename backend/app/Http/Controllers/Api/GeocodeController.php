<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Geo\NominatimGeocoder;
use Illuminate\Http\Request;

/**
 * Public address lookup for the shuttle booking forms (website + app).
 * Proxies OpenStreetMap Nominatim server-side so we control caching,
 * the User-Agent and the 1 req/s usage policy in one place.
 */
class GeocodeController extends Controller
{
    public function __construct(protected NominatimGeocoder $geo) {}

    /** GET /api/geocode/autocomplete?q=... -> [{label, full, lat, lng}] */
    public function autocomplete(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 3 || mb_strlen($q) > 200) {
            return response()->json([]);
        }

        return response()->json($this->geo->search($q, 6));
    }

    /** GET /api/geocode/reverse?lat=..&lng=.. -> {label, full, lat, lng} (404 when unknown) */
    public function reverse(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $this->geo->reverse((float) $data['lat'], (float) $data['lng']);

        if (! $result) {
            return response()->json(['message' => "We couldn't look up an address for your location."], 404);
        }

        return response()->json($result);
    }
}
