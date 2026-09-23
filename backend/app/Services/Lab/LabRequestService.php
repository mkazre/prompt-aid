<?php

namespace App\Services\Lab;

use App\Contracts\NotificationDispatcherInterface;
use App\Models\DoctorProfile;
use App\Models\LabRequest;
use App\Models\LabResult;
use App\Models\PatientProfile;
use App\Models\ThirdPartyProfile;
use App\Models\User;
use App\Support\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Doctor → Third Party (lab/imaging provider) diagnostic request workflow:
 * doctor logs a request for tests -> nearby/any active third party accepts
 * -> collects the sample -> processes -> uploads results, visible to both
 * the requesting doctor and the patient.
 */
class LabRequestService
{
    public function __construct(protected NotificationDispatcherInterface $notifier) {}

    /**
     * @param  array<int, array{test_name: string, sample_type?: string, notes?: string}>  $items
     */
    public function create(
        DoctorProfile $doctor,
        PatientProfile $patient,
        array $items,
        ?int $appointmentId = null,
        string $priority = 'routine',
        ?string $clinicalNotes = null,
        ?string $collectionAddress = null,
        ?float $collectionLat = null,
        ?float $collectionLng = null,
    ): LabRequest {
        return DB::transaction(function () use ($doctor, $patient, $items, $appointmentId, $priority, $clinicalNotes, $collectionAddress, $collectionLat, $collectionLng) {
            $request = LabRequest::query()->create([
                'doctor_profile_id' => $doctor->id,
                'patient_profile_id' => $patient->id,
                'appointment_id' => $appointmentId,
                'priority' => $priority,
                'clinical_notes' => $clinicalNotes,
                'collection_address' => $collectionAddress ?? $patient->address,
                'collection_lat' => $collectionLat ?? $patient->lat,
                'collection_lng' => $collectionLng ?? $patient->lng,
                'status' => LabRequest::STATUS_REQUESTED,
            ]);

            foreach ($items as $item) {
                $request->items()->create($item);
            }

            $this->notifier->email($patient->user, 'Lab request logged', "Dr. {$doctor->user->name} has requested tests for you. A lab partner will be in touch to collect a sample.");

            $labPartners = User::query()->where('role', User::ROLE_THIRD_PARTY)->where('status', 'active')->get();
            if ($labPartners->isNotEmpty()) {
                StaffNotifier::alert(
                    $labPartners,
                    'New lab request available',
                    "Dr. {$doctor->user->name} logged a {$priority} request ({$request->request_ref}) for {$patient->user->name}.",
                    icon: 'heroicon-o-beaker',
                    color: $priority === 'urgent' ? 'danger' : 'warning',
                    url: route('filament.partner.resources.lab-requests.index'),
                    actionLabel: 'Review',
                );
            }

            return $request->load('items');
        });
    }

    public function accept(LabRequest $request, ThirdPartyProfile $thirdParty): LabRequest
    {
        $request->update([
            'third_party_profile_id' => $thirdParty->id,
            'status' => LabRequest::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        $this->notifier->push($request->patient->user, 'Lab request accepted', "{$thirdParty->company_name} will collect your sample soon.");

        StaffNotifier::alert(
            $request->doctor->user,
            'Lab request accepted',
            "{$thirdParty->company_name} accepted the {$request->request_ref} request for {$request->patient->user->name}.",
            icon: 'heroicon-o-beaker',
            color: 'success',
            url: route('filament.admin.resources.lab-requests.index'),
        );

        return $request->fresh();
    }

    public function markSampleCollected(LabRequest $request): LabRequest
    {
        $request->update(['status' => LabRequest::STATUS_SAMPLE_COLLECTED, 'sample_collected_at' => now()]);

        return $request->fresh();
    }

    public function markProcessing(LabRequest $request): LabRequest
    {
        $request->update(['status' => LabRequest::STATUS_PROCESSING]);

        return $request->fresh();
    }

    public function uploadResult(LabRequest $request, User $uploader, string $label, string $filePath, ?string $summary = null): LabResult
    {
        $result = $request->results()->create([
            'uploaded_by' => $uploader->id,
            'label' => $label,
            'file_path' => $filePath,
            'summary' => $summary,
            'visible_to_patient' => true,
        ]);

        $request->update(['status' => LabRequest::STATUS_COMPLETED, 'completed_at' => now()]);

        $this->notifier->email($request->patient->user, 'Your lab results are ready', "Results for your {$request->request_ref} request are now available in your dashboard.");
        $this->notifier->email($request->doctor->user, 'Lab results uploaded', "Results for {$request->patient->user->name}'s {$request->request_ref} request are ready to review.");

        StaffNotifier::alert(
            $request->doctor->user,
            'Lab results ready to review',
            "Results for {$request->patient->user->name}'s {$request->request_ref} request have been uploaded.",
            icon: 'heroicon-o-document-check',
            color: 'success',
            url: route('filament.admin.resources.lab-requests.index'),
        );

        return $result;
    }

    public function cancel(LabRequest $request): LabRequest
    {
        $request->update(['status' => LabRequest::STATUS_CANCELLED]);

        return $request->fresh();
    }
}
