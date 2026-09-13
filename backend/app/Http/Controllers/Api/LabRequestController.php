<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabRequestResource;
use App\Models\DoctorProfile;
use App\Models\LabRequest;
use App\Models\PatientProfile;
use App\Services\Lab\LabRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LabRequestController extends Controller
{
    public function __construct(protected LabRequestService $service) {}

    /** Doctor: log a new diagnostic request for a patient. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'patient_profile_id' => ['required', 'integer', 'exists:patient_profiles,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'priority' => ['required', 'in:routine,urgent'],
            'clinical_notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.test_name' => ['required', 'string'],
            'items.*.sample_type' => ['nullable', 'string'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $doctor = $request->user()->doctorProfile;
        $patient = PatientProfile::findOrFail($data['patient_profile_id']);

        $labRequest = $this->service->create(
            $doctor,
            $patient,
            $data['items'],
            $data['appointment_id'] ?? null,
            $data['priority'],
            $data['clinical_notes'] ?? null,
        );

        return new LabRequestResource($labRequest->load(['doctor.user', 'patient.user', 'items']));
    }

    /** Doctor: my logged requests. */
    public function doctorIndex(Request $request)
    {
        $requests = $request->user()->doctorProfile->labRequests()
            ->with(['patient.user', 'thirdParty', 'items', 'results'])
            ->latest()
            ->paginate(15);

        return LabRequestResource::collection($requests);
    }

    /** Patient: my requests + results. */
    public function patientIndex(Request $request)
    {
        $requests = $request->user()->patientProfile->labRequests()
            ->with(['doctor.user', 'thirdParty', 'items', 'results'])
            ->latest()
            ->paginate(15);

        return LabRequestResource::collection($requests);
    }

    /** Third party: open requests (not yet accepted). */
    public function available(Request $request)
    {
        $requests = LabRequest::query()
            ->where('status', LabRequest::STATUS_REQUESTED)
            ->whereNull('third_party_profile_id')
            ->with(['doctor.user', 'patient.user', 'items'])
            ->latest('requested_at')
            ->get();

        return LabRequestResource::collection($requests);
    }

    /** Third party: requests I've accepted (in progress + completed). */
    public function myRequests(Request $request)
    {
        $requests = $request->user()->thirdPartyProfile->labRequests()
            ->with(['doctor.user', 'patient.user', 'items', 'results'])
            ->latest()
            ->paginate(15);

        return LabRequestResource::collection($requests);
    }

    public function accept(Request $request, LabRequest $labRequest)
    {
        $labRequest = $this->service->accept($labRequest, $request->user()->thirdPartyProfile);

        return new LabRequestResource($labRequest);
    }

    public function updateStatus(Request $request, LabRequest $labRequest)
    {
        abort_unless($labRequest->third_party_profile_id === $request->user()->thirdPartyProfile?->id, 403);

        $data = $request->validate(['status' => ['required', 'in:sample_collected,processing']]);

        $labRequest = $data['status'] === 'sample_collected'
            ? $this->service->markSampleCollected($labRequest)
            : $this->service->markProcessing($labRequest);

        return new LabRequestResource($labRequest);
    }

    public function uploadResult(Request $request, LabRequest $labRequest)
    {
        abort_unless($labRequest->third_party_profile_id === $request->user()->thirdPartyProfile?->id, 403);

        $data = $request->validate([
            'label' => ['required', 'string'],
            'summary' => ['nullable', 'string'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $path = $request->file('file')->store('lab-results', 'public');

        $result = $this->service->uploadResult($labRequest, $request->user(), $data['label'], $path, $data['summary'] ?? null);

        return response()->json(['result' => $result, 'request' => new LabRequestResource($labRequest->fresh(['results']))], 201);
    }

    public function show(Request $request, LabRequest $labRequest)
    {
        $user = $request->user();
        $owns = $labRequest->patient_profile_id === $user->patientProfile?->id
            || $labRequest->doctor_profile_id === $user->doctorProfile?->id
            || $labRequest->third_party_profile_id === $user->thirdPartyProfile?->id;

        abort_unless($owns, 403);

        return new LabRequestResource($labRequest->load(['doctor.user', 'patient.user', 'thirdParty', 'items', 'results']));
    }
}
