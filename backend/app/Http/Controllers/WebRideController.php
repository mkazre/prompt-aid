<?php

namespace App\Http\Controllers;

use App\Models\EmergencyContact;
use App\Models\Ride;
use App\Models\Setting;
use App\Services\Rides\PricingService;
use App\Services\Rides\RideActionException;
use App\Services\Rides\RideDispatchService;
use App\Services\Rides\RideSeriesService;
use App\Support\ShuttleSettings;
use Illuminate\Http\Request;

/**
 * Patient-side shuttle actions on the website (session auth). Every action
 * is ownership-checked: the ride must belong to the signed-in patient.
 */
class WebRideController extends Controller
{
    public function store(Request $request, RideDispatchService $dispatch, PricingService $pricing)
    {
        // The confirm form is rendered by POST /shuttle/quote, so "back" would be a GET
        // to a POST-only URL (405). Failures always return to the shuttle form instead.
        $shuttleForm = route('shuttle').'#request';
        $rules = [
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['nullable', 'in:sedan,suv,van,wheelchair_accessible,stretcher'],
            'wait_and_return' => ['nullable', 'boolean'],
            'quote_token' => ['nullable', 'string', 'max:4000'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'pickup_notes' => ['nullable', 'string', 'max:500'],
        ];
        try {
            $data = $request->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e->redirectTo($shuttleForm);
        }

        $patient = $request->user()->patientProfile;
        if (! $patient) {
            return redirect()->to($shuttleForm)->withInput()->withErrors(['pickup_address' => 'Shuttle trips can only be booked from a patient account. Log in with your patient account to book.']);
        }

        // One live on-demand trip at a time (scheduled rides don't count).
        // Stale (long-over) rides never block a new booking — Ride::isActive().
        $active = $patient->rides()->whereNull('scheduled_for')
            ->whereNotIn('status', Ride::TERMINAL)->latest('requested_at')->get()->first(fn (Ride $r) => $r->isActive());
        if ($active) {
            return redirect()->route('rides.track', $active)->with('error', 'You already have a trip in progress. Cancel it first to book another.');
        }

        $vehicle = $data['vehicle_type'] ?? 'sedan';
        $locked = $pricing->verifyQuote($data['quote_token'] ?? null, $vehicle, (float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng']);

        if (! $locked) {
            // Expired or tampered quote: re-quote and ask the patient to confirm the new price.
            return app(ShuttlePageController::class)->renderQuote(
                $request, $pricing,
                $data['pickup_address'], ['lat' => (float) $data['pickup_lat'], 'lng' => (float) $data['pickup_lng']],
                $data['dropoff_address'], ['lat' => (float) $data['dropoff_lat'], 'lng' => (float) $data['dropoff_lng']],
                'Your previous price expired. Here is an updated fare — please confirm again.',
            );
        }

        $ride = $dispatch->requestRide(
            $patient,
            $data['pickup_address'], (float) $data['pickup_lat'], (float) $data['pickup_lng'],
            $data['dropoff_address'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng'],
            null,
            $vehicle,
            (bool) Setting::get('ride_auto_assign_shuttle', true),
            $locked,
            null,
            isset($data['radius_km']) ? (float) $data['radius_km'] : ($patient->search_radius_km !== null ? (float) $patient->search_radius_km : null),
            $data['pickup_notes'] ?? null,
            $request->user()->id,
        );

        if (! empty($data['wait_and_return'])) {
            $ride->update(['wait_and_return' => true]);
        }

        if (app(\App\Services\Payments\RidePaymentService::class)->awaiting($ride)) {
            // Pay first (payment sheet opens on the tracking page); drivers are alerted once it is secured.
            return redirect()->to(route('rides.track', $ride).'?pay=ride:'.$ride->id)->with('success', 'Ride booked at R '.number_format((float) $ride->quoted_fare, 2).'. Complete the payment to send it to drivers.');
        }

        return redirect()->route('rides.track', $ride)->with('success', 'Ride confirmed at R '.number_format((float) $ride->quoted_fare, 2).'. We are matching you with a nearby driver.');
    }

    /** Patient: request the return leg of an already-booked ride from the tracking page. */
    public function requestReturn(Request $request, Ride $ride, RideSeriesService $series)
    {
        $this->authorizePatient($request, $ride);

        if ($ride->return_of_ride_id || Ride::query()->where('return_of_ride_id', $ride->id)->exists()) {
            return back()->with('error', 'This ride already has a return leg.');
        }

        $data = $request->validate(['scheduled_for' => ['nullable', 'date', 'after:now']]);

        $returnLeg = $series->requestReturnLeg($ride, $data['scheduled_for'] ?? null);

        return redirect()->route('rides.track', $returnLeg)->with('success', 'Return ride requested.');
    }

    /** Exact fee the patient would pay if they cancel now (shown before confirming). */
    public function cancelPreview(Request $request, Ride $ride, RideDispatchService $dispatch)
    {
        $this->authorizePatient($request, $ride);

        return response()->json($dispatch->cancelPreview($ride) + [
            'reasons' => ShuttleSettings::json('shuttle_cancel_reasons_patient'),
        ]);
    }

    public function cancel(Request $request, Ride $ride, RideDispatchService $dispatch)
    {
        $this->authorizePatient($request, $ride);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'expected_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $ride = $dispatch->cancelByPatient($ride, $data['reason'] ?? null, isset($data['expected_fee']) ? (float) $data['expected_fee'] : null);
        } catch (RideActionException $e) {
            return $this->fail($request, $e);
        }

        $msg = $ride->cancellation_fee > 0
            ? 'Ride cancelled. A cancellation fee of R '.number_format((float) $ride->cancellation_fee, 2).' was charged.'
            : 'Ride cancelled. No fee was charged.';

        return $request->expectsJson()
            ? response()->json(['message' => $msg, 'status' => $ride->status, 'fee' => (float) $ride->cancellation_fee])
            : redirect()->route('rides.track', $ride)->with('success', $msg);
    }

    public function retry(Request $request, Ride $ride, RideDispatchService $dispatch)
    {
        $this->authorizePatient($request, $ride);

        $data = $request->validate(['radius_km' => ['nullable', 'numeric', 'min:1', 'max:500']]);

        try {
            $dispatch->retryDispatch($ride, isset($data['radius_km']) ? (float) $data['radius_km'] : null);
        } catch (RideActionException $e) {
            return $this->fail($request, $e);
        }

        return response()->json(['message' => 'Searching for a driver again.']);
    }

    public function sos(Request $request, Ride $ride, RideDispatchService $dispatch)
    {
        $this->authorizePatient($request, $ride);

        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $dispatch->sos($ride->loadMissing('driver.user'), $request->user(), isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);

        return response()->json([
            'message' => 'Our support team has been alerted and can see your trip.',
            'contacts' => EmergencyContact::activeOrdered()->map(fn ($c) => ['label' => $c->label, 'phone' => $c->phone, 'tel_url' => $c->tel_url, 'is_primary' => (bool) $c->is_primary])->values(),
        ]);
    }

    public function rate(Request $request, Ride $ride, RideDispatchService $dispatch)
    {
        $this->authorizePatient($request, $ride);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $dispatch->rateByPatient($ride, (int) $data['rating'], $data['comment'] ?? null);
        } catch (RideActionException $e) {
            return $this->fail($request, $e);
        }

        return response()->json(['message' => 'Thanks for rating your trip.']);
    }

    protected function authorizePatient(Request $request, Ride $ride): void
    {
        $pid = $request->user()?->patientProfile?->id;
        abort_unless($pid && $ride->patient_profile_id === $pid, 403);
    }

    protected function fail(Request $request, RideActionException $e)
    {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage()], $e->status)
            : back()->with('error', $e->getMessage());
    }
}
