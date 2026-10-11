<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Doctors\DoctorMatchPricing;
use App\Services\Doctors\DoctorMatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * On-demand "See a doctor now" (video or in-person), parallel to the
 * browse-a-doctor/pick-a-slot flow in AppointmentController. Every
 * endpoint is scoped to the signed-in patient's / doctor's own rows.
 * The website uses the same DoctorMatchService via Account\DoctorNowController.
 */
class DoctorMatchController extends Controller
{
    public function __construct(protected DoctorMatchService $match, protected DoctorMatchPricing $pricing) {}

    // ------------------------------------------------------------------ patient

    public function specialties()
    {
        return response()->json(['data' => DoctorMatchService::specialties()]);
    }

    public function quote(Request $request)
    {
        $data = $request->validate([
            'specialty' => ['required', 'string', 'max:255'],
            'mode' => ['required', 'in:video,in_person'],
        ]);

        return response()->json(['data' => $this->pricing->quote($data['specialty'], $data['mode'])]);
    }

    /** Patient: request an instant video/in-person match (back-compat: returns the appointment + `match`). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'specialty' => ['required', 'string', 'max:255'],
            'mode' => ['required', 'in:video,in_person'],
            'lat' => ['required_if:mode,in_person', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_if:mode,in_person', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403);

        try {
            $match = $this->match->start($patient, $data['specialty'], $data['mode'], isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);
        } catch (DoctorMatchException $e) {
            $open = $this->match->openRequestFor($patient);

            return response()->json(['message' => $e->getMessage(), 'match' => $open ? $this->match->patientPayload($open) : null], $e->status);
        }

        return (new AppointmentResource($match->appointment->load(['doctor.user', 'patient.user'])))
            ->additional(['match' => $this->match->patientPayload($match)]);
    }

    public function current(Request $request)
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403);
        $match = $this->match->currentRequestFor($patient);
        if ($match) {
            $this->match->progress($match);
        }

        return response()->json(['data' => $match ? $this->match->patientPayload($match->fresh()) : null]);
    }

    public function show(Request $request, int $id)
    {
        $match = $this->ownedRequest($request, $id);
        $this->match->progress($match);

        return response()->json(['data' => $this->match->patientPayload($match->fresh())]);
    }

    public function cancel(Request $request, int $id)
    {
        $match = $this->ownedRequest($request, $id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->guard(fn () => ['data' => $this->match->patientPayload($this->match->cancelByPatient($match, $data['reason'] ?? null))]);
    }

    public function waitlist(Request $request, int $id)
    {
        $match = $this->ownedRequest($request, $id);
        $this->match->joinWaitlist($match);

        return response()->json(['data' => $this->match->patientPayload($match->fresh())]);
    }

    protected function ownedRequest(Request $request, int $id): DoctorMatchRequest
    {
        $patientId = $request->user()->patientProfile?->id;
        $match = DoctorMatchRequest::query()->find($id);
        // 404 (not 403) so request ids can't be probed.
        abort_unless($match && $patientId && $match->patient_profile_id === $patientId, 404);

        return $match;
    }

    // ------------------------------------------------------------------ doctor

    /** Console heartbeat (every ~20s while the doctor has the console open). */
    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);

        $this->match->heartbeat($doctor, isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);
        $doctor->refresh();

        return response()->json([
            'availability' => $doctor->availability,
            'last_seen_at' => $doctor->last_seen_at?->toIso8601String(),
            'offer' => $this->match->doctorOfferPayload($this->match->currentOfferFor($doctor)),
        ]);
    }

    public function currentOffer(Request $request)
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);

        return response()->json([
            'data' => $this->match->doctorOfferPayload($this->match->currentOfferFor($doctor)),
            'availability' => $doctor->fresh()->availability,
        ]);
    }

    public function acceptOffer(Request $request, int $offer)
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);
        $model = DoctorMatchOffer::query()->find($offer);
        abort_unless($model && $model->doctor_profile_id === $doctor->id, 404);

        return $this->guard(function () use ($model, $doctor) {
            $req = $this->match->accept($model, $doctor);

            return ['data' => $this->match->doctorConsultPayload($req->load(['appointment.patient.user', 'clinic']))];
        });
    }

    public function declineOffer(Request $request, int $offer)
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);
        $model = DoctorMatchOffer::query()->find($offer);
        abort_unless($model && $model->doctor_profile_id === $doctor->id, 404);

        return $this->guard(function () use ($model, $doctor) {
            $this->match->decline($model, $doctor);

            return ['ok' => true];
        });
    }

    public function currentConsult(Request $request)
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);

        return response()->json(['data' => $this->match->doctorConsultPayload($this->match->activeConsultFor($doctor))]);
    }

    /** Doctor: requests currently offered to them (legacy list endpoint — now offer-based). */
    public function available(Request $request)
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);
        $offer = $this->match->currentOfferFor($doctor);

        $appointments = Appointment::query()
            ->whereKey($offer ? [$offer->appointment_id] : [0])
            ->where('status', Appointment::STATUS_MATCHING)
            ->with(['patient.user'])
            ->get();

        return AppointmentResource::collection($appointments)->additional(['offer' => $this->match->doctorOfferPayload($offer)]);
    }

    /** Doctor: accept by appointment id (legacy) — only works for the doctor's live offer. */
    public function accept(Request $request, Appointment $appointment)
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);

        $offer = DoctorMatchOffer::query()
            ->where('appointment_id', $appointment->id)
            ->where('doctor_profile_id', $doctor->id)
            ->where('status', DoctorMatchOffer::OFFERED)
            ->latest('id')
            ->first();

        if (! $offer) {
            return response()->json(['message' => 'This request is not currently offered to you.'], 409);
        }

        try {
            $req = $this->match->accept($offer, $doctor);
        } catch (DoctorMatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return new AppointmentResource($req->appointment->load(['patient.user']));
    }

    /** Doctor: toggle online/offline for instant matching. */
    public function toggleAvailability(Request $request)
    {
        $data = $request->validate(['availability' => ['required', 'in:available,offline']]);
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403);

        return $this->guard(fn () => ['availability' => $this->match->setAvailability($doctor, $data['availability'])->availability]);
    }

    protected function guard(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (DoctorMatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
