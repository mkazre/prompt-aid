<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use App\Services\Rides\RideDispatchService;
use App\Services\Rides\RideSeriesService;
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
            'wait_and_return' => ['nullable', 'boolean'],
        ]);

        $ride = $dispatch->requestRide(
            $request->user()->patientProfile,
            $data['pickup_address'], $data['pickup_lat'], $data['pickup_lng'],
            $data['dropoff_address'], $data['dropoff_lat'], $data['dropoff_lng'],
            null,
            $data['vehicle_type'] ?? 'sedan',
        );

        if (! empty($data['wait_and_return'])) {
            $ride->update(['wait_and_return' => true]);
        }

        return redirect()->route('rides.track', $ride)->with('success', 'Ride requested! We are matching you with a nearby driver.');
    }

    /** Patient: request the return leg of an already-booked ride from the tracking page. */
    public function requestReturn(Request $request, Ride $ride, RideSeriesService $series)
    {
        abort_unless($ride->patient_profile_id === $request->user()->patientProfile?->id, 403);

        if ($ride->return_of_ride_id || Ride::query()->where('return_of_ride_id', $ride->id)->exists()) {
            return back()->with('error', 'This ride already has a return leg.');
        }

        $data = $request->validate(['scheduled_for' => ['nullable', 'date']]);

        $returnLeg = $series->requestReturnLeg($ride, $data['scheduled_for'] ?? null);

        return redirect()->route('rides.track', $returnLeg)->with('success', 'Return ride requested.');
    }
}
