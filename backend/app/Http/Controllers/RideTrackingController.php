<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use Illuminate\Http\Request;

class RideTrackingController extends Controller
{
    public function show(Request $request, Ride $ride)
    {
        abort_unless($ride->patient_profile_id === $request->user()->patientProfile->id, 403);

        return view('rides.track', ['ride' => $ride->load(['driver.user'])]);
    }

    /** Lightweight JSON polled by the tracking map — session-authenticated, no Sanctum token needed. */
    public function status(Request $request, Ride $ride)
    {
        abort_unless($ride->patient_profile_id === $request->user()->patientProfile->id, 403);

        $ride->load('driver.user');

        return response()->json([
            'status' => $ride->status,
            'eta_minutes' => $ride->eta_minutes,
            'driver' => $ride->driver ? [
                'name' => $ride->driver->user->name,
                'vehicle_make' => $ride->driver->vehicle_make,
                'vehicle_model' => $ride->driver->vehicle_model,
                'vehicle_plate_no' => $ride->driver->vehicle_plate_no,
                'current_lat' => $ride->driver->current_lat,
                'current_lng' => $ride->driver->current_lng,
            ] : null,
        ]);
    }
}
