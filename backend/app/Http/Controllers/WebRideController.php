<?php

namespace App\Http\Controllers;

use App\Services\Rides\RideDispatchService;
use Illuminate\Http\Request;

class WebRideController extends Controller
{
    public function store(Request $request, RideDispatchService $dispatch)
    {
        $data = $request->validate([
            'pickup_address' => ['required', 'string'],
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'dropoff_address' => ['required', 'string'],
            'dropoff_lat' => ['required', 'numeric'],
            'dropoff_lng' => ['required', 'numeric'],
            'vehicle_type' => ['nullable', 'in:sedan,suv,van,wheelchair_accessible'],
        ]);

        $ride = $dispatch->requestRide(
            $request->user()->patientProfile,
            $data['pickup_address'], $data['pickup_lat'], $data['pickup_lng'],
            $data['dropoff_address'], $data['dropoff_lat'], $data['dropoff_lng'],
            null,
            $data['vehicle_type'] ?? 'sedan',
        );

        return redirect()->route('rides.track', $ride)->with('success', 'Ride requested! We are matching you with a nearby driver.');
    }
}
