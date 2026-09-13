<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\Ride;
use App\Models\RideReview;
use App\Services\Rides\RideDispatchService;
use Illuminate\Http\Request;

class RideController extends Controller
{
    public function __construct(protected RideDispatchService $dispatch) {}

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
            'vehicle_type' => ['nullable', 'in:sedan,suv,van,wheelchair_accessible'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $ride = $this->dispatch->requestRide(
            $request->user()->patientProfile,
            $data['pickup_address'], $data['pickup_lat'], $data['pickup_lng'],
            $data['dropoff_address'], $data['dropoff_lat'], $data['dropoff_lng'],
            $data['appointment_id'] ?? null,
            $data['vehicle_type'] ?? 'sedan',
        );

        return new RideResource($ride->load(['driver.user', 'statusEvents']));
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
        $this->authorizeRideAccess($request, $ride);

        return new RideResource($ride->load(['patient.user', 'driver.user', 'statusEvents']));
    }

    /** Patient: cancel a ride still in a cancellable state. */
    public function cancel(Request $request, Ride $ride)
    {
        $this->authorizeRideAccess($request, $ride);

        $ride = $this->dispatch->updateStatus($ride, Ride::STATUS_CANCELLED);

        return new RideResource($ride);
    }

    /** Patient: rate a completed ride. */
    public function review(Request $request, Ride $ride)
    {
        $this->authorizeRideAccess($request, $ride);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = RideReview::query()->updateOrCreate(['ride_id' => $ride->id], $data);

        return response()->json(['review' => $review]);
    }

    /** Driver: rides available to accept nearby (unassigned, requested). */
    public function available(Request $request)
    {
        $rides = Ride::query()
            ->where('status', Ride::STATUS_REQUESTED)
            ->whereNull('driver_profile_id')
            ->with(['patient.user'])
            ->latest('requested_at')
            ->get();

        return RideResource::collection($rides);
    }

    /** Driver: accept an unassigned ride. */
    public function accept(Request $request, Ride $ride)
    {
        $driver = $request->user()->driverProfile;

        if ($ride->driver_profile_id) {
            return response()->json(['message' => 'This ride has already been accepted.'], 409);
        }

        $ride = $this->dispatch->assignDriver($ride, $driver);

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
        ]);

        $ride = $this->dispatch->updateStatus($ride, $data['status'], $data['lat'] ?? null, $data['lng'] ?? null);

        return new RideResource($ride);
    }

    /** Driver: push a live location ping (also updates active ride's trail). */
    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
        ]);

        $this->dispatch->updateDriverLocation($request->user()->driverProfile, $data['lat'], $data['lng']);

        return response()->json(['message' => 'Location updated.']);
    }

    /** Driver: toggle online/offline availability. */
    public function toggleAvailability(Request $request)
    {
        $data = $request->validate(['availability' => ['required', 'in:available,offline']]);

        $request->user()->driverProfile->update(['availability' => $data['availability']]);

        return response()->json(['availability' => $data['availability']]);
    }

    protected function authorizeRideAccess(Request $request, Ride $ride): void
    {
        $user = $request->user();
        $owns = $ride->patient_profile_id === $user->patientProfile?->id
            || $ride->driver_profile_id === $user->driverProfile?->id;

        abort_unless($owns, 403);
    }

    protected function authorizeDriverOwnsRide(Request $request, Ride $ride): void
    {
        abort_unless($ride->driver_profile_id === $request->user()->driverProfile?->id, 403);
    }
}
