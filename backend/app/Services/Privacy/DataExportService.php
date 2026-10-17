<?php

namespace App\Services\Privacy;

use App\Models\Consent;
use App\Models\DataAccessLog;
use App\Models\DsrRequest;
use App\Models\Message;
use App\Models\User;
use App\Support\PrivacySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds a POPIA s23 access-request export of everything we hold about a
 * user, as JSON (zipped when ZipArchive is available), on the PRIVATE
 * `local` disk under privacy-exports/. Uploaded files (ID copies, lab
 * result PDFs, chat attachments) are listed by name only, not embedded —
 * they can be supplied on request; this keeps exports small enough to
 * build synchronously on shared hosting.
 *
 * Every section is built independently: one failing relation records an
 * error string for that section rather than failing the whole export.
 */
class DataExportService
{
    public const DISK = 'local';

    public const DIR = 'privacy-exports';

    public function __construct(protected AccessLogger $accessLogger) {}

    /** @return array<string, mixed> */
    public function collect(User $user): array
    {
        $patient = $user->patientProfile;
        $pid = $patient?->id;

        $sections = [
            'account' => fn () => collect($user->toArray())->except(['password', 'remember_token'])->all(),
            'consents' => fn () => Consent::query()->where('user_id', $user->id)->orderBy('granted_at')
                ->get(['purpose', 'version', 'granted_at', 'withdrawn_at', 'channel', 'ip'])->toArray(),
            'patient_profile' => fn () => $patient?->toArray(),
            'medical_scheme_memberships' => fn () => $pid ? $patient->schemeMemberships()->with('scheme:id,name')->get()->toArray() : [],
            'uploaded_documents' => fn () => $pid ? $patient->documents()->get(['id', 'type', 'original_name', 'created_at'])->toArray() : [],
            'appointments' => fn () => $pid ? $patient->appointments()
                ->with(['doctor.user:id,name', 'clinic:id,name', 'service:id,name'])
                ->orderBy('date')->get()
                ->map(fn ($a) => [
                    'booking_ref' => $a->booking_ref, 'date' => (string) $a->date, 'start_time' => $a->start_time,
                    'visit_type' => $a->visit_type, 'mode' => $a->mode, 'status' => $a->status, 'reason' => $a->reason,
                    'doctor' => $a->doctor?->user?->name, 'clinic' => $a->clinic?->name, 'service' => $a->service?->name,
                ])->all() : [],
            'consultations_and_prescriptions' => fn () => $pid ? $patient->appointments()
                ->whereHas('encounter')->with(['encounter.prescription.items'])->get()
                ->map(fn ($a) => [
                    'booking_ref' => $a->booking_ref,
                    'date' => (string) $a->date,
                    'encounter' => collect($a->encounter?->toArray())->only(['vitals', 'chief_complaint', 'diagnosis', 'notes', 'follow_up_date', 'created_at'])->all(),
                    'prescription' => $a->encounter?->prescription ? [
                        'notes' => $a->encounter->prescription->notes,
                        'items' => $a->encounter->prescription->items->map(fn ($i) => collect($i->toArray())->except(['id', 'prescription_id'])->all())->all(),
                    ] : null,
                ])->all() : [],
            'lab_requests_and_results' => fn () => $pid ? $patient->labRequests()->with('results')->get()
                ->map(fn ($r) => [
                    'request_ref' => $r->request_ref, 'status' => $r->status, 'priority' => $r->priority,
                    'requested_at' => (string) $r->requested_at, 'completed_at' => (string) $r->completed_at,
                    'results' => $r->results->where('visible_to_patient', true)->map(fn ($res) => [
                        'label' => $res->label, 'summary' => $res->summary, 'critical' => $res->critical,
                        'file' => basename((string) $res->file_path), 'created_at' => (string) $res->created_at,
                    ])->values()->all(),
                ])->all() : [],
            'prescription_uploads' => fn () => $pid ? $patient->prescriptionUploads()->get(['id', 'status', 'notes', 'review_notes', 'created_at'])->toArray() : [],
            'shuttle_rides' => fn () => $pid ? DB::table('rides')->where('patient_profile_id', $pid)
                ->get(['ride_ref', 'status', 'pickup_address', 'dropoff_address', 'distance_km', 'fare_final', 'requested_at', 'completed_at'])
                ->map(fn ($r) => (array) $r)->all() : [],
            'shuttle_trip_audio_recordings' => fn () => app(\App\Services\Safety\RideRecordingService::class)->exportFor($user),
            'pharmacy_orders' => fn () => $pid ? $patient->orders()->with('items')->get()->toArray() : [],
            'invoices' => fn () => $pid ? $patient->invoices()->with('items')->get()->toArray() : [],
            'messages' => fn () => $this->messages($user, $pid),
            'notifications' => fn () => DB::table('user_notifications')->where('user_id', $user->id)
                ->orderBy('created_at')->get(['type', 'title', 'body', 'read_at', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            'triage_assessments' => fn () => DB::table('triage_submissions')->where('user_id', $user->id)
                ->get(['reference', 'level', 'age_band', 'symptoms', 'facility_types', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            'driver_profile' => fn () => $user->driverProfile?->toArray(),
            'record_access_history' => fn () => $pid ? DataAccessLog::query()->where('patient_profile_id', $pid)
                ->with('user:id,name')->orderBy('created_at')->get()
                ->map(fn ($l) => ['when' => (string) $l->created_at, 'who' => $l->user?->name, 'role' => $l->role, 'what' => $l->subjectLabel(), 'action' => $l->action])
                ->all() : [],
            'privacy_requests' => fn () => DsrRequest::query()->where('user_id', $user->id)
                ->get(['type', 'status', 'details', 'created_at', 'completed_at'])->toArray(),
        ];

        $data = [
            'generated_at' => now()->toIso8601String(),
            'data_subject' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'about' => 'This file contains the personal information Prompt Aid holds about you, provided under section 23 of the Protection of Personal Information Act. Uploaded files are listed by name; contact the Information Officer for copies.',
        ];

        foreach ($sections as $name => $builder) {
            try {
                $data[$name] = $builder();
            } catch (\Throwable $e) {
                report($e);
                $data[$name] = ['error' => 'This section could not be compiled automatically; the Information Officer will supply it.'];
            }
        }

        return $data;
    }

    /**
     * Generates the export file, attaches it to the DSR and returns it.
     */
    public function generate(DsrRequest $dsr): DsrRequest
    {
        $user = $dsr->user;
        if (! $user) {
            throw new \RuntimeException('This request has no linked user account.');
        }

        $json = json_encode($this->collect($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $base = self::DIR.'/'.$user->id.'/dsr-'.$dsr->id.'-'.Str::random(24);
        $disk = Storage::disk(self::DISK);

        $this->deleteExport($dsr);

        if (class_exists(\ZipArchive::class)) {
            $tmp = tempnam(sys_get_temp_dir(), 'paexp');
            $zip = new \ZipArchive;
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) === true) {
                $zip->addFromString('prompt-aid-my-data.json', $json);
                $zip->addFromString('README.txt', "Prompt Aid personal information export\nGenerated: ".now()->toDayDateTimeString()."\n\nOpen prompt-aid-my-data.json in any text editor.\n");
                $zip->close();
                $path = $base.'.zip';
                $disk->put($path, file_get_contents($tmp));
                @unlink($tmp);
            } else {
                $path = $base.'.json';
                $disk->put($path, $json);
            }
        } else {
            $path = $base.'.json';
            $disk->put($path, $json);
        }

        $days = max(1, PrivacySettings::int('privacy_retention_dsr_exports_days'));

        $dsr->forceFill([
            'export_path' => $path,
            'export_size' => $disk->size($path),
            'export_expires_at' => now()->addDays($days),
            'status' => DsrRequest::STATUS_COMPLETED,
            'completed_at' => $dsr->completed_at ?? now(),
        ])->save();

        if ($user->patientProfile) {
            $this->accessLogger->log($user->patientProfile, 'export', 'dsr:'.$dsr->id);
        }

        return $dsr;
    }

    public function deleteExport(DsrRequest $dsr): void
    {
        if ($dsr->export_path) {
            try {
                Storage::disk(self::DISK)->delete($dsr->export_path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function messages(User $user, ?int $pid): array
    {
        if (! Schema::hasTable('messages')) {
            return [];
        }

        $conversationIds = DB::table('conversations')
            ->when($pid, fn ($q) => $q->where('patient_profile_id', $pid), fn ($q) => $q->whereRaw('1 = 0'))
            ->pluck('id');

        return Message::query()
            ->where(fn ($q) => $q->whereIn('conversation_id', $conversationIds)->orWhere('sender_user_id', $user->id))
            ->with('sender:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'conversation_id' => $m->conversation_id,
                'from' => $m->sender_user_id === $user->id ? 'you' : $m->sender?->name,
                'body' => $m->body,
                'attachment' => $m->attachment_name,
                'sent_at' => (string) $m->created_at,
            ])->all();
    }
}
