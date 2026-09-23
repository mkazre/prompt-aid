<?php

namespace App\Http\Controllers;

use App\Services\Rides\RideDispatchService;
use Illuminate\Http\Request;

class ShuttlePageController extends Controller
{
    public function index()
    {
        return view('pages.shuttle', ['quotes' => null]);
    }

    /**
     * Server-side "get a fare estimate" step: geocode both addresses and
     * price every vehicle type, then re-render the same form with the
     * quotes and resolved coordinates filled in as hidden fields so the
     * confirm button can POST straight to the real rides.store endpoint
     * without a second geocoding round-trip.
     */
    public function quote(Request $request, RideDispatchService $dispatch)
    {
        $data = $request->validate([
            'pickup_address' => ['required', 'string'],
            'dropoff_address' => ['required', 'string'],
        ]);

        $geo = app(\App\Contracts\GeocodingInterface::class);
        $pickup = $geo->geocode($data['pickup_address']);
        $dropoff = $geo->geocode($data['dropoff_address']);

        $quotes = $dispatch->quotesFor($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']);

        return view('pages.shuttle', [
            'quotes' => $quotes,
            'pickup_address' => $data['pickup_address'],
            'dropoff_address' => $data['dropoff_address'],
            'pickup_lat' => $pickup['lat'],
            'pickup_lng' => $pickup['lng'],
            'dropoff_lat' => $dropoff['lat'],
            'dropoff_lng' => $dropoff['lng'],
        ]);
    }
}
