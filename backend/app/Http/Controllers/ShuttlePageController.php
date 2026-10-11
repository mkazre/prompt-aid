<?php

namespace App\Http\Controllers;

use App\Contracts\GeocodingInterface;
use App\Services\Rides\PricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShuttlePageController extends Controller
{
    /**
     * The shuttle page. Accepts optional prefill query params so other pages
     * (home hero widget, clinic/doctor "Book a shuttle here" links) can hand
     * over what the patient already typed/picked:
     *   ?from=&from_lat=&from_lng=&to=&to_lat=&to_lng=
     */
    public function index(Request $request)
    {
        return view('pages.shuttle', [
            'quotes' => null,
            'pickup_address' => (string) $request->query('from', ''),
            'pickup_lat' => $this->coord($request->query('from_lat'), 90),
            'pickup_lng' => $this->coord($request->query('from_lng'), 180),
            'dropoff_address' => (string) $request->query('to', ''),
            'dropoff_lat' => $this->coord($request->query('to_lat'), 90),
            'dropoff_lng' => $this->coord($request->query('to_lng'), 180),
        ]);
    }

    /**
     * Server-side "get a fare estimate" step. Uses the coordinates the
     * address typeahead put in the hidden lat/lng fields; only geocodes the
     * free text as a fallback (e.g. JS disabled or the user never picked a
     * suggestion). Re-renders the same page with quotes + resolved
     * coordinates so the confirm button POSTs straight to rides.store.
     */
    public function quote(Request $request, PricingService $pricing, GeocodingInterface $geo)
    {
        $data = $request->validate([
            'pickup_address' => ['required', 'string', 'max:255'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $pickup = $this->resolve($geo, $data, 'pickup');
        $dropoff = $this->resolve($geo, $data, 'dropoff');

        $errors = [];
        if (! $pickup) {
            $errors['pickup_address'] = "We couldn't find that pickup address — pick one from the suggestions.";
        }
        if (! $dropoff) {
            $errors['dropoff_address'] = "We couldn't find that drop-off address — pick one from the suggestions.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors)->redirectTo(route('shuttle').'#request');
        }

        return $this->renderQuote($request, $pricing, $data['pickup_address'], $pickup, $data['dropoff_address'], $dropoff);
    }

    /**
     * Quote results view — also used by WebRideController when a booking
     * arrives with an expired/mismatched quote (re-quote with a notice).
     *
     * @param  array{lat: float, lng: float}  $pickup
     * @param  array{lat: float, lng: float}  $dropoff
     */
    public function renderQuote(Request $request, PricingService $pricing, string $pickupAddress, array $pickup, string $dropoffAddress, array $dropoff, ?string $notice = null)
    {
        $quotes = $pricing->quotesFor($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']);
        $route = $pricing->routeFor($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']);

        // Guests must log in to confirm — send them back to this trip afterwards
        // (WebAuthController::login uses redirect()->intended()).
        if (! $request->user()) {
            $request->session()->put('url.intended', route('shuttle', [
                'from' => $pickupAddress, 'from_lat' => $pickup['lat'], 'from_lng' => $pickup['lng'],
                'to' => $dropoffAddress, 'to_lat' => $dropoff['lat'], 'to_lng' => $dropoff['lng'],
            ]).'#request');
        }

        return view('pages.shuttle', [
            'quotes' => $quotes,
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'pickup_lat' => $pickup['lat'],
            'pickup_lng' => $pickup['lng'],
            'dropoff_lat' => $dropoff['lat'],
            'dropoff_lng' => $dropoff['lng'],
            'distance_km' => $route['distance_km'],
            'duration_min' => $route['duration_min'],
            'route_source' => $route['source'],
            'route_points' => $route['geometry'],
            'quote_notice' => $notice,
        ]);
    }

    /** @return array{lat: float, lng: float}|null */
    protected function resolve(GeocodingInterface $geo, array $data, string $leg): ?array
    {
        $lat = $data[$leg.'_lat'] ?? null;
        $lng = $data[$leg.'_lng'] ?? null;

        if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }

        return $geo->geocode($data[$leg.'_address']);
    }

    protected function coord($value, int $max): ?float
    {
        if (! is_numeric($value) || abs((float) $value) > $max) {
            return null;
        }

        return (float) $value;
    }
}
