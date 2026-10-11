<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\Doctors\DoctorMatchService;
use Illuminate\Http\Request;

/**
 * On-demand "request a doctor" matching (video or in-person), parallel to
 * the existing browse-a-doctor/pick-a-slot flow in AppointmentController.
 */
class DoctorMatchController extends Controller
{
    public function __construct(protected DoctorMatchService $match) {}

    /** Patient: request an instant video/in-person match. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'specialty' => ['required', 'string'],
            'mode' => ['required', 'in:video,in_person'],
            'lat' => ['required_if:mode,in_person', 'nullable', 'numeric'],
            'lng' => ['required_if:mode,in_person', 'nullable', 'numeric'],
        ]);

        $appointment = $this->match->requestMatch(
            $request->user()->patientProfile,
            $data['specialty'],
            $data['mode'],
            $data['lat'] ?? null,
            $data['lng'] ?? null,
        );

        return new AppointmentResource($appointment->load(['doctor.user', 'patient.user']));
    }

    /** Patient: "Get connected to a Doctor in 30min" — broadcast to every green GP. */
    public function storeInstant(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:video,in_person'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->match->greenGps()->isEmpty()) {
            return response()->json(['message' => 'No GP is available to see you within 30 minutes right now. Please book a doctor manually or use Ask a Doctor on WhatsApp.'], 409);
        }

        $appointment = $this->match->requestInstantGp($request->user()->patientProfile, $data['mode'], $data['reason'] ?? null);

        return new AppointmentResource($appointment->load(['doctor.user', 'patient.user']));
    }

    /** Doctor: unmatched requests they are allowed to take. */
    public function available(Request $request)
    {
        $doctor = $request->user()->doctorProfile;

        $appointments = Appointment::query()
            ->where('status', Appointment::STATUS_MATCHING)
            ->whereNull('doctor_profile_id')
            ->where(function ($q) use ($doctor) {
                $q->where('requested_specialty', $doctor->specialization);
                if ($doctor->isGeneralPractitioner()) {
                    $q->orWhere('requested_specialty', DoctorMatchService::INSTANT_GP_SPECIALTY);
                }
            })
            ->with(['patient.user'])
            ->latest()
            ->get();

        return AppointmentResource::collection($appointments);
    }

    /** Doctor: accept an unmatched request (first doctor to accept wins). */
    public function accept(Request $request, Appointment $appointment)
    {
        $doctor = $request->user()->doctorProfile;

        if ($appointment->doctor_profile_id || $appointment->status !== Appointment::STATUS_MATCHING) {
            return response()->json(['message' => 'This request has already been matched.'], 409);
        }

        if (! $this->match->canAccept($appointment, $doctor)) {
            return response()->json(['message' => 'This request is not in your specialty.'], 409);
        }

        $claimed = $this->match->claim($appointment, $doctor);

        if (! $claimed) {
            return response()->json(['message' => 'Another doctor accepted this request first.'], 409);
        }

        return new AppointmentResource($claimed->load(['patient.user']));
    }

    /** Doctor: their current live status. */
    public function showAvailability(Request $request)
    {
        return response()->json(['availability' => $request->user()->doctorProfile->availability]);
    }

    /** Doctor: set their live status — available (green), busy (orange) or offline (red). */
    public function toggleAvailability(Request $request)
    {
        $data = $request->validate(['availability' => ['required', 'in:available,busy,offline']]);

        $request->user()->doctorProfile->update(['availability' => $data['availability']]);

        return response()->json(['availability' => $data['availability']]);
    }
}
