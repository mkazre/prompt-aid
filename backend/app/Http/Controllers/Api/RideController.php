<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Http\Resources\RideSeriesResource;
use App\Models\Ride;
use App\Models\RideReview;
use App\Models\Setting;
use App\Models\RideOffer;
use App\Services\Rides\PricingService;
use App\Services\Rides\RideActionException;
use App\Services\Rides\RideDispatchService;
use App\Services\Rides\RideLocationService;
use App\Services\Rides\RideSeriesService;
use App\Services\Rides\RideStatusPresenter;
use App\Support\ShuttleSettings;
use Illuminate\Http\Request;

class RideController extends Controller
{
    public function __construct(
        protected RideDispatchService $dispatch,
        protected RideSeriesService $series,
        protected PricingService $pricing,
    ) {}

    /** Public: live fare quotes for every vehicle type — powers the "choose your ride" screen. */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'dropoff_lat' => ['required', 'numeric'],
            'dropoff_lng' => ['required', 'numeric'],
        ]);

        return response()->json([
            'quotes' => $this->dispatch->quotesFor($data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng']),
        ]);
    }

    /**
     * Mobile has no on-device geocoder — this mirrors the website's
     * /shuttle/quote flow (address text -> geocode -> quotes) so the app
     * can get a real fare estimate from free-text addresses alone,
     * without a Google/Mapbox API key.
     */
    public function quoteByAddress(Request $request)
    {
        $data = $request->validate([
            'pickup_address' => ['required', 'string', 'max:255'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $geo = app(\App\Contracts\GeocodingInterface::class);

        // Prefer coordinates the client already has (picked suggestion / GPS);
        // only geocode free text as a fallback.
        $pickup = isset($data['pickup_lat'], $data['pickup_lng'])
            ? ['lat' => (float) $data['pickup_lat'], 'lng' => (float) $data['pickup_lng']]
            : $geo->geocode($data['pickup_address']);
        $dropoff = isset($data['dropoff_lat'], $data['dropoff_lng'])
            ? ['lat' => (float) $data['dropoff_lat'], 'lng' => (float) $data['dropoff_lng']]
            : $geo->geocode($data['dropoff_address']);

        $errors = [];
        if (! $pickup) {
            $errors['pickup_address'] = ["We couldn't find that pickup address — pick one from the suggestions."];
        }
        if (! $dropoff) {
            $errors['dropoff_address'] = ["We couldn't find that drop-off address — pick one from the suggestions."];
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return response()->json([
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'quotes' => $this->dispatch->quotesFor($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']),
        ]);
    }

    /** Patient: request a shuttle ride. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'pickup_address' => ['required', 'string'],
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'dropoff_address' => ['required', 'string'],
            'dropoff_lat' => ['required', 'numeric'],
            'dropoff_lng' => ['required', 'numeric'],
            'vehicle_type' => ['nullable', 'in:sedan,suv,van,wheelchair_accessible,stretcher'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'wait_and_return' => ['nullable', 'boolean'],
            'quote_token' => ['nullable', 'string', 'max:4000'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'pickup_notes' => ['nullable', 'string', 'max:500'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
        ]);

        $appointmentId = $data['appointment_id'] ?? null;
        $patient = $request->user()->patientProfile;

        // One live on-demand trip at a time. Stale / closed rides never block (Ride::isActive()).
        if (empty($data['scheduled_for'])) {
            $active = $patient->rides()->whereNull('scheduled_for')->whereNotIn('status', Ride::TERMINAL)
                ->latest('requested_at')->get()->first(fn (Ride $r) => $r->isActive());
            if ($active) {
                return response()->json(['message' => 'You already have a trip in progress. Cancel it first to book another.', 'ride_id' => $active->id, 'active_ride' => new RideResource($active)], 409);
            }
        }
        $vehicle = $data['vehicle_type'] ?? 'sedan';

        // Quote lock (additive): a valid token honours the quoted price; an
        // expired/mismatched one returns 409 with fresh quotes. No token
        // (older app builds) = priced at booking time.
        $locked = null;
        if (! empty($data['quote_token'])) {
            $locked = $this->pricing->verifyQuote($data['quote_token'], $vehicle, (float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng']);
            if (! $locked) {
                return response()->json([
                    'message' => 'Your quote expired. Please confirm the updated fare.',
                    'quotes' => $this->pricing->quotesFor((float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng']),
                ], 409);
            }
        }

        // Appointment-linked and standalone shuttle bookings are admin-configurable
        // independently, even though they share this one endpoint.
        $autoAssign = $appointmentId
            ? (bool) Setting::get('ride_auto_assign_appointment', true)
            : (bool) Setting::get('ride_auto_assign_shuttle', true);

        $ride = $this->dispatch->requestRide(
            $patient,
            $data['pickup_address'], $data['pickup_lat'], $data['pickup_lng'],
            $data['dropoff_address'], $data['dropoff_lat'], $data['dropoff_lng'],
            $appointmentId,
            $vehicle,
            $autoAssign,
            $locked,
            $data['scheduled_for'] ?? null,
            isset($data['radius_km']) ? (float) $data['radius_km'] : ($patient->search_radius_km !== null ? (float) $patient->search_radius_km : null),
            $data['pickup_notes'] ?? null,
            $request->user()->id,
        );

        if (! empty($data['wait_and_return'])) {
            $ride->update(['wait_and_return' => true]);
        }

        return new RideResource($ride->load(['driver.user', 'statusEvents']));
    }

    /** Patient: request the return leg of an already-booked ride. */
    public function requestReturn(Request $request, Ride $ride)
    {
        $this->authorizeRideAccess($request, $ride);

        $data = $request->validate([
            'scheduled_for' => ['nullable', 'date'],
        ]);

        if ($ride->return_of_ride_id || Ride::query()->where('return_of_ride_id', $ride->id)->exists()) {
            return response()->json(['message' => 'This ride already has a return leg.'], 409);
        }

        $returnLeg = $this->series->requestReturnLeg($ride, $data['scheduled_for'] ?? null);

        return new RideResource($returnLeg->load(['driver.user', 'statusEvents']));
    }

    /** Patient: create a recurring shuttle series (e.g. dialysis 3x/week). */
    public function storeSeries(Request $request)
    {
        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'min:0', 'max:6'],
            'time' => ['required', 'date_format:H:i'],
            'until' => ['required', 'date', 'after:today'],
            'pickup_address' => ['required', 'string'],
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'dropoff_address' => ['required', 'string'],
            'dropoff_lat' => ['required', 'numeric'],
            'dropoff_lng' => ['required', 'numeric'],
            'vehicle_type' => ['nullable', 'in:sedan,suv,van,wheelchair_accessible,stretcher'],
        ]);

        $rideSeries = $this->series->create(
            $request->user()->patientProfile,
            ['days' => $data['days'], 'time' => $data['time'], 'until' => $data['until']],
            ['address' => $data['pickup_address'], 'lat' => $data['pickup_lat'], 'lng' => $data['pickup_lng']],
            ['address' => $data['dropoff_address'], 'lat' => $data['dropoff_lat'], 'lng' => $data['dropoff_lng']],
            $data['vehicle_type'] ?? 'sedan',
        );

        return new RideSeriesResource($rideSeries);
    }

    /** Patient: my recurring series. */
    public function indexSeries(Request $request)
    {
        $series = $request->user()->patientProfile->rideSeries()->latest()->get();

        return RideSeriesResource::collection($series);
    }

    /** Patient: pause a recurring series (stops future materialisation). */
    public function pauseSeries(Request $request, \App\Models\RideSeries $rideSeries)
    {
        abort_unless($rideSeries->patient_profile_id === $request->user()->patientProfile?->id, 403);

        $this->series->pause($rideSeries);

        return new RideSeriesResource($rideSeries->fresh());
    }

    /** Patient: resume a paused recurring series. */
    public function resumeSeries(Request $request, \App\Models\RideSeries $rideSeries)
    {
        abort_unless($rideSeries->patient_profile_id === $request->user()->patientProfile?->id, 403);

        $this->series->resume($rideSeries);

        return new RideSeriesResource($rideSeries->fresh());
    }

    /** Patient: my ride history. */
    public function index(Request $request)
    {
        $rides = $request->user()->patientProfile->rides()
            ->with(['driver.user'])
            ->latest('requested_at')
            ->paginate(15);

        return RideResource::collection($rides);
    }

    /** Patient or driver: live ride detail + tracking events. */
    public function show(Request $request, Ride $ride)
    {
        $this->authorizeRideAccess($request, $ride, allowOfferedDriver: true);

        return new RideResource($ride->load(['patient.user', 'driver.user', 'statusEvents']));
    }

    /** Patient: live tracking payload (same JSON as the website map: driver position, ETA, route, PIN...). */
    public function live(Request $request, Ride $ride, RideStatusPresenter $presenter)
    {
        abort_unless($request->user()->patientProfile && $ride->patient_profile_id === $request->user()->patientProfile->id, 403);

        return response()->json($presenter->forPatient($ride, $request->query('rk'), $request->query('tk')));
    }

    /** Patient: exact cancellation fee if cancelled now. */
    public function cancelPreview(Request $request, Ride $ride)
    {
        abort_unless($request->user()->patientProfile && $ride->patient_profile_id === $request->user()->patientProfile->id, 403);

        return response()->json($this->dispatch->cancelPreview($ride) + [
            'reasons' => ShuttleSettings::json('shuttle_cancel_reasons_patient'),
        ]);
    }

    /**
     * Patient cancels (fee per policy; optional expected_fee guard) or the
     * assigned driver cancels (no patient fee; ride re-dispatched).
     */
    public function cancel(Request $request, Ride $ride)
    {
        $this->authorizeRideAccess($request, $ride);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'expected_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();

        try {
            if ($user->driverProfile && $ride->driver_profile_id === $user->driverProfile->id) {
                $this->dispatch->cancelByDriver($ride, $user->driverProfile, $data['reason'] ?? 'Cancelled from the driver app');
                $ride = $ride->fresh();
            } else {
                $ride = $this->dispatch->cancelByPatient($ride, $data['reason'] ?? null, isset($data['expected_fee']) ? (float) $data['expected_fee'] : null);
            }
        } catch (RideActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return new RideResource($ride);
    }

    /** Patient: SOS during a trip — alerts the support team (same as the website button). */
    public function sos(Request $request, Ride $ride)
    {
        abort_unless($request->user()->patientProfile && $ride->patient_profile_id === $request->user()->patientProfile->id, 403);

        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->dispatch->sos($ride->loadMissing('driver.user'), $request->user(), isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);

        return response()->json([
            'message' => 'Our support team has been alerted and can see your trip.',
            'contacts' => \App\Models\EmergencyContact::activeOrdered()->map(fn ($c) => ['label' => $c->label, 'phone' => $c->phone, 'tel_url' => $c->tel_url, 'is_primary' => (bool) $c->is_primary])->values(),
        ]);
    }

    /** Patient: rate a completed ride. */
    public function review(Request $request, Ride $ride)
    {
        $this->authorizeRideAccess($request, $ride);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $request->user()->patientProfile || $ride->patient_profile_id !== $request->user()->patientProfile->id) {
            // Driver rating the patient.
            try {
                $this->dispatch->rateByDriver($ride, $request->user()->driverProfile, (int) $data['rating'], $data['comment'] ?? null);
            } catch (RideActionException $e) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return response()->json(['message' => 'Rating saved.']);
        }

        try {
            $review = $this->dispatch->rateByPatient($ride, (int) $data['rating'], $data['comment'] ?? null);
        } catch (RideActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['review' => $review]);
    }

    /** Driver: rides available to accept nearby (unassigned, requested). */
    public function available(Request $request)
    {
        $driver = $request->user()->driverProfile;

        // Lazy dispatch progression so offers advance even without cron.
        if (\Illuminate\Support\Facades\Cache::add('driver-api:tick', 1, 4)) {
            $this->dispatch->tickAll();
        }

        // Rides currently offered to this driver (sequential dispatch) +
        // the open queue (auto-assign off / no driver found) this vehicle can serve.
        $offeredIds = RideOffer::query()->where('driver_profile_id', $driver->id)
            ->where('status', RideOffer::PENDING)->where('expires_at', '>', now())->pluck('ride_id');

        $rides = Ride::query()
            ->where('status', Ride::STATUS_REQUESTED)
            ->whereNull('driver_profile_id')
            ->where(fn ($q) => $q->whereIn('id', $offeredIds)
                ->orWhere(fn ($q2) => $q2->where(fn ($q3) => $q3->whereNull('dispatch_state')->orWhereIn('dispatch_state', [Ride::DISPATCH_QUEUE, Ride::DISPATCH_NO_DRIVERS]))
                    ->whereIn('vehicle_type', $this->servableTypes($driver->vehicle_type))))
            ->with(['patient.user'])
            ->latest('requested_at')
            ->limit(50)
            ->get()
            // Never offer stale / long-over requests (closed by the dispatch close-out).
            ->filter(fn (Ride $r) => $r->isActive())
            ->values();

        return RideResource::collection($rides);
    }

    /** Driver: accept an unassigned ride. */
    public function accept(Request $request, Ride $ride)
    {
        $driver = $request->user()->driverProfile;

        if ($ride->driver_profile_id) {
            return response()->json(['message' => 'This ride has already been accepted.'], 409);
        }

        try {
            // Accepts this driver's pending offer, or claims an open-queue ride.
            $ride = $this->dispatch->claim($ride, $driver);
        } catch (RideActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return new RideResource($ride);
    }

    /** Driver: decline the ride currently offered to them (moves to the next driver). */
    public function decline(Request $request, Ride $ride)
    {
        $driver = $request->user()->driverProfile;
        $offer = RideOffer::query()->where('ride_id', $ride->id)->where('driver_profile_id', $driver->id)->where('status', RideOffer::PENDING)->first();
        if ($offer) {
            $this->dispatch->declineOffer($offer, $driver);
        }

        return response()->json(['message' => 'Offer declined.']);
    }

    /** Driver: mark the patient as a no-show after the configured wait at pickup. */
    public function noShow(Request $request, Ride $ride)
    {
        $this->authorizeDriverOwnsRide($request, $ride);

        try {
            $ride = $this->dispatch->markNoShow($ride, $request->user()->driverProfile);
        } catch (RideActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return new RideResource($ride);
    }

    /** Driver: my active + past rides. */
    public function myRides(Request $request)
    {
        $rides = $request->user()->driverProfile->rides()
            ->with(['patient.user'])
            ->latest('requested_at')
            ->paginate(15);

        return RideResource::collection($rides);
    }

    /** Driver: advance a ride's status (driver_enroute, arrived, in_progress, completed). */
    public function updateStatus(Request $request, Ride $ride)
    {
        $this->authorizeDriverOwnsRide($request, $ride);

        $data = $request->validate([
            'status' => ['required', 'in:driver_enroute,arrived,in_progress,completed'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'pin' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $ride = $this->dispatch->driverUpdateStatus(
                $ride, $request->user()->driverProfile, $data['status'],
                isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null,
                $data['pin'] ?? null, true,
            );
        } catch (RideActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return new RideResource($ride);
    }

    /** Driver: push a live location ping (also updates active ride's trail). */
    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $driver = $request->user()->driverProfile;
        $active = app(RideLocationService::class)->activeRideFor($driver);
        if ($active && $active->isSimulated()) {
            return response()->json(['message' => 'Location ignored while a TEST simulation is running.', 'accepted' => false]);
        }

        $result = $this->dispatch->updateDriverLocation(
            $driver, (float) $data['lat'], (float) $data['lng'],
            isset($data['heading']) ? (float) $data['heading'] : null,
            isset($data['speed']) ? (float) $data['speed'] * 3.6 : null,
        );

        return response()->json(['message' => $result['accepted'] ? 'Location updated.' : 'Location ignored.'] + $result);
    }

    /** Driver: toggle online/offline availability. */
    public function toggleAvailability(Request $request)
    {
        $data = $request->validate(['availability' => ['required', 'in:available,offline']]);
        $driver = $request->user()->driverProfile;
        $active = app(RideLocationService::class)->activeRideFor($driver);

        if ($data['availability'] === 'available' && ($blocker = $driver->complianceBlocker())) {
            return response()->json(['message' => "You can't go online until your documents are up to date: {$blocker}."], 403);
        }
        if ($data['availability'] === 'offline' && $active) {
            return response()->json(['message' => 'Finish or cancel your current trip before going offline.'], 409);
        }

        $value = $data['availability'] === 'available' && $active ? 'busy' : $data['availability'];
        $driver->update(['availability' => $value]);

        return response()->json(['availability' => $value]);
    }

    protected function authorizeRideAccess(Request $request, Ride $ride, bool $allowOfferedDriver = false): void
    {
        $user = $request->user();
        $driverId = $user->driverProfile?->id;
        $owns = ($user->patientProfile && $ride->patient_profile_id === $user->patientProfile->id)
            || ($driverId && $ride->driver_profile_id === $driverId);

        // A driver may view a ride currently offered to them, or an open-queue ride they could claim.
        if (! $owns && $allowOfferedDriver && $driverId && $ride->status === Ride::STATUS_REQUESTED && ! $ride->driver_profile_id) {
            $owns = RideOffer::query()->where('ride_id', $ride->id)->where('driver_profile_id', $driverId)->where('status', RideOffer::PENDING)->exists()
                || in_array($ride->dispatch_state, [null, Ride::DISPATCH_QUEUE, Ride::DISPATCH_NO_DRIVERS], true);
        }

        abort_unless($owns, 403);
    }

    /** @return array<int, string> ride vehicle types this driver's vehicle may serve */
    protected function servableTypes(?string $driverVehicle): array
    {
        return array_values(array_filter(
            ['sedan', 'suv', 'van', 'wheelchair_accessible', 'stretcher'],
            fn ($t) => in_array($driverVehicle, RideDispatchService::compatibleVehicleTypes($t), true),
        ));
    }

    protected function authorizeDriverOwnsRide(Request $request, Ride $ride): void
    {
        abort_unless($ride->driver_profile_id === $request->user()->driverProfile?->id, 403);
    }
}
