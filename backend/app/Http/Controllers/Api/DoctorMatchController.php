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

    /** Doctor: unmatched requests in their own specialty. */
    public function available(Request $request)
    {
        $doctor = $request->user()->doctorProfile;

        $appointments = Appointment::query()
            ->where('status', Appointment::STATUS_MATCHING)
            ->whereNull('doctor_profile_id')
            ->where('requested_specialty', $doctor->specialization)
            ->with(['patient.user'])
            ->latest()
            ->get();

        return AppointmentResource::collection($appointments);
    }

    /** Doctor: accept an unmatched request. */
    public function accept(Request $request, Appointment $appointment)
    {
        $doctor = $request->user()->doctorProfile;

        if ($appointment->doctor_profile_id || $appointment->status !== Appointment::STATUS_MATCHING) {
            return response()->json(['message' => 'This request has already been matched.'], 409);
        }

        if ($appointment->requested_specialty !== $doctor->specialization) {
            return response()->json(['message' => 'This request is not in your specialty.'], 409);
        }

        $appointment = $this->match->assignDoctor($appointment, $doctor);

        return new AppointmentResource($appointment->load(['patient.user']));
    }

    /** Doctor: toggle online/offline for instant matching. */
    public function toggleAvailability(Request $request)
    {
        $data = $request->validate(['availability' => ['required', 'in:available,offline']]);

        $request->user()->doctorProfile->update(['availability' => $data['availability']]);

        return response()->json(['availability' => $data['availability']]);
    }
}
