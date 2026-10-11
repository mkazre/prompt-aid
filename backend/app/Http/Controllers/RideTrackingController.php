<?php

namespace App\Http\Controllers;

use App\Models\EmergencyContact;
use App\Models\Ride;
use App\Services\Rides\RideStatusPresenter;
use App\Support\ShuttleSettings;
use Illuminate\Http\Request;

class RideTrackingController extends Controller
{
    public function show(Request $request, Ride $ride, RideStatusPresenter $presenter)
    {
        $this->authorizePatient($request, $ride);

        $ride->load(['driver.user', 'review', 'returnLeg', 'charges']);

        return view('rides.track', [
            'ride' => $ride,
            'initial' => $presenter->forPatient($ride),
            'emergencyContacts' => EmergencyContact::activeOrdered(),
            'cancelReasons' => ShuttleSettings::json('shuttle_cancel_reasons_patient'),
        ]);
    }

    /**
     * Polled every ~3.5 s by the tracking map (session auth). Also drives the
     * lazy dispatch + TEST simulator progression. `rk`/`tk` = route keys
     * the client already has (polylines are omitted when unchanged).
     */
    public function status(Request $request, Ride $ride, RideStatusPresenter $presenter)
    {
        $this->authorizePatient($request, $ride);

        return response()->json($presenter->forPatient($ride, $request->query('rk'), $request->query('tk')));
    }

    protected function authorizePatient(Request $request, Ride $ride): void
    {
        $pid = $request->user()?->patientProfile?->id;
        abort_unless($pid && $ride->patient_profile_id === $pid, 403);
    }
}
