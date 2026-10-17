<?php

namespace App\Services\Privacy;

use App\Models\DsrRequest;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\PrivacySettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carries out an admin-approved POPIA deletion request by ANONYMISING, not
 * hard-deleting, because some records must legally be kept:
 *
 *  - Clinical records (consult notes, prescriptions, lab results, triage
 *    assessments) — HPCSA Booklet 9: at least `privacy_clinical_retention_years`
 *    (default 6) after the last entry. Kept, still linked to the patient's
 *    name/date of birth so they remain usable as a medical record, until
 *    that date; then AnonymiseExpiredClinicalRecords strips them via
 *    finalise().
 *  - Invoices, payments, claims, pharmacy orders — Tax Administration Act /
 *    Companies Act (5 years). Kept; delivery addresses removed.
 *  - Prescription uploads — Pharmacy Act/SAPC script retention. Kept.
 *
 * Removed immediately: login credentials, email, phone, avatar, address &
 * GPS, emergency contacts, ID/medical-aid card uploads, notifications, API
 * tokens, all consents (withdrawn), precise ride locations (addresses
 * removed, coordinates coarsened to ~1 km), and PI copies inside audit log
 * diffs for the user/profile.
 *
 * Writes go through the query builder (not Eloquent) on purpose: the
 * Auditable trait would otherwise copy the very PI being erased into
 * audit_logs.old_values.
 */
class AccountAnonymiser
{
    public function __construct(protected ConsentService $consents) {}

    /** @return array{removed: array<int,string>, kept: array<int,string>, retain_until: ?string} */
    public function anonymise(DsrRequest $dsr, ?User $admin = null): array
    {
        $user = $dsr->user;
        if (! $user) {
            throw new \RuntimeException('This request has no linked user account.');
        }
        if (in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN], true)) {
            throw new \RuntimeException('Staff administrator accounts must be removed manually after reassigning their responsibilities.');
        }

        $patient = $user->patientProfile;
        $removed = [];
        $kept = [];

        $retainUntil = $patient ? $this->clinicalRetentionDate($patient) : null;

        DB::transaction(function () use ($user, $patient, $retainUntil, &$removed, &$kept) {
            $this->consents->withdrawAll($user);
            $removed[] = 'All consents withdrawn (ledger kept as proof of what was agreed).';

            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $removed[] = 'App tokens and web sessions revoked.';

            foreach (['user_notifications' => 'user_id', 'notifications_log' => 'user_id'] as $table => $col) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where($col, $user->id)->delete();
                }
            }
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
            }
            $removed[] = 'Notifications and notification delivery log deleted.';

            if ($patient) {
                DB::table('patient_profiles')->where('id', $patient->id)->update([
                    'address' => null, 'lat' => null, 'lng' => null,
                    'emergency_contact_name' => null, 'emergency_contact_phone' => null,
                    'updated_at' => now(),
                ]);
                $removed[] = 'Home address, GPS location and emergency contact removed.';

                $docs = DB::table('patient_documents')->where('patient_profile_id', $patient->id)->get(['id', 'path']);
                foreach ($docs as $doc) {
                    $this->deleteFile('public', $doc->path);
                }
                DB::table('patient_documents')->where('patient_profile_id', $patient->id)->delete();
                $removed[] = $docs->count().' uploaded ID / medical aid card file(s) deleted.';

                if (Schema::hasTable('rides')) {
                    $rides = DB::table('rides')->where('patient_profile_id', $patient->id)
                        ->update([
                            'pickup_address' => '[removed]',
                            'dropoff_address' => '[removed]',
                            'pickup_lat' => DB::raw('ROUND(pickup_lat, 2)'),
                            'pickup_lng' => DB::raw('ROUND(pickup_lng, 2)'),
                            'dropoff_lat' => DB::raw('ROUND(dropoff_lat, 2)'),
                            'dropoff_lng' => DB::raw('ROUND(dropoff_lng, 2)'),
                        ]);
                    $removed[] = "Shuttle trip addresses removed and coordinates coarsened to ~1 km ({$rides} trip(s)); trip, fare and payment rows kept for financial records.";
                }

                DB::table('orders')->where('patient_profile_id', $patient->id)
                    ->update(['delivery_address' => null, 'delivery_lat' => null, 'delivery_lng' => null]);
                DB::table('lab_requests')->where('patient_profile_id', $patient->id)
                    ->update(['collection_address' => null, 'collection_lat' => null, 'collection_lng' => null]);
                $removed[] = 'Delivery and sample-collection addresses removed.';

                $kept[] = 'Invoices, payments, claims, scheme membership and pharmacy orders — kept for 5 years (Tax Administration Act / Companies Act).';
                $kept[] = 'Uploaded prescriptions — kept per Pharmacy Act / SAPC record-keeping rules.';
            }

            if (Schema::hasTable('ride_recordings')) {
                $rec = app(\App\Services\Safety\RideRecordingService::class)->purgeForUser($user->id, $patient?->id);
                if ($rec['deleted']) {
                    $removed[] = "{$rec['deleted']} shuttle trip audio recording(s) deleted.";
                }
                if ($rec['kept']) {
                    $kept[] = "{$rec['kept']} shuttle trip audio recording(s) on legal hold (SOS / complaint / investigation) — kept until the hold is released, then deleted.";
                }
            }

            if (Schema::hasTable('triage_submissions')) {
                DB::table('triage_submissions')->where('user_id', $user->id)->update([
                    'pickup_lat' => null, 'pickup_lng' => null, 'pickup_address' => null,
                    'emergency_contact_name' => null, 'emergency_contact_phone' => null, 'ip' => null,
                ]);
            }

            $this->deleteFile('public', $user->avatar);

            $hasClinical = $retainUntil !== null;
            DB::table('users')->where('id', $user->id)->update([
                'name' => $hasClinical ? $user->name : 'Deleted user #'.$user->id,
                'email' => 'deleted-'.$user->id.'-'.Str::lower(Str::random(6)).'@anonymised.invalid',
                'phone' => null,
                'avatar' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'status' => 'inactive',
                'updated_at' => now(),
            ]);
            $removed[] = 'Email, phone, photo and password removed; account can no longer sign in.';

            if ($hasClinical) {
                $kept[] = 'Clinical record (name, date of birth, consultations, prescriptions, lab results, triage) — kept until '.$retainUntil->toDateString()
                    .' (HPCSA: '.PrivacySettings::int('privacy_clinical_retention_years').' years after the last entry), then anonymised automatically.';
                $kept[] = 'Chat messages with practitioners — may form part of the clinical record; purged by the chat retention schedule.';
            } else {
                $this->stripClinicalIdentity($user->id, $patient?->id);
                $removed[] = 'No clinical records held — name and health profile anonymised immediately.';
            }

            $this->redactAuditLogs($user->id, $patient?->id);
            $removed[] = 'Personal details copied into audit-log diffs redacted (event history kept).';
        });

        $dsr->forceFill([
            'anonymised_at' => now(),
            'retain_clinical_until' => $retainUntil?->toDateString(),
            'fully_anonymised_at' => $retainUntil ? null : now(),
            'handled_by' => $admin?->id ?? $dsr->handled_by,
            'status' => DsrRequest::STATUS_COMPLETED,
            'completed_at' => now(),
            'retention_summary' => "REMOVED:\n- ".implode("\n- ", $removed).($kept ? "\n\nKEPT (and why):\n- ".implode("\n- ", $kept) : ''),
        ])->save();

        return ['removed' => $removed, 'kept' => $kept, 'retain_until' => $retainUntil?->toDateString()];
    }

    /**
     * Run once retain_clinical_until has passed: removes the remaining
     * identity and clinical free text. Rows stay (de-identified) so
     * aggregate counts and financial links don't break.
     */
    public function finalise(DsrRequest $dsr): void
    {
        $userId = $dsr->user_id;
        if (! $userId) {
            $dsr->forceFill(['fully_anonymised_at' => now()])->save();

            return;
        }

        $patientId = DB::table('patient_profiles')->where('user_id', $userId)->value('id');

        DB::transaction(function () use ($userId, $patientId) {
            $this->stripClinicalIdentity($userId, $patientId);

            if ($patientId) {
                $appointmentIds = DB::table('appointments')->where('patient_profile_id', $patientId)->pluck('id');
                DB::table('appointments')->whereIn('id', $appointmentIds)->update(['reason' => null, 'cancel_reason' => null]);
                DB::table('encounters')->whereIn('appointment_id', $appointmentIds)
                    ->update(['vitals' => null, 'chief_complaint' => null, 'diagnosis' => null, 'notes' => null]);

                $labRequestIds = DB::table('lab_requests')->where('patient_profile_id', $patientId)->pluck('id');
                DB::table('lab_requests')->whereIn('id', $labRequestIds)->update(['clinical_notes' => null]);
                foreach (DB::table('lab_results')->whereIn('lab_request_id', $labRequestIds)->pluck('file_path') as $path) {
                    $this->deleteFile('public', $path);
                }
                DB::table('lab_results')->whereIn('lab_request_id', $labRequestIds)->update(['summary' => null, 'file_path' => '[removed]']);

                foreach (DB::table('prescription_uploads')->where('patient_profile_id', $patientId)->pluck('file_path') as $path) {
                    $this->deleteFile('public', $path);
                }
                DB::table('prescription_uploads')->where('patient_profile_id', $patientId)->update(['file_path' => '[removed]', 'notes' => null]);

                if (Schema::hasTable('conversations')) {
                    $conversationIds = DB::table('conversations')->where('patient_profile_id', $patientId)->pluck('id');
                    foreach (DB::table('messages')->whereIn('conversation_id', $conversationIds)->whereNotNull('attachment_path')->pluck('attachment_path') as $path) {
                        $this->deleteFile('local', $path);
                    }
                    DB::table('messages')->whereIn('conversation_id', $conversationIds)
                        ->update(['body' => null, 'attachment_path' => null, 'attachment_name' => null]);
                }
            }

            if (Schema::hasTable('triage_submissions')) {
                DB::table('triage_submissions')->where('user_id', $userId)->update(['user_id' => null]);
            }
        });

        $dsr->forceFill(['fully_anonymised_at' => now()])->save();
    }

    public function clinicalRetentionDate(PatientProfile $patient): ?Carbon
    {
        $appointmentIds = DB::table('appointments')->where('patient_profile_id', $patient->id)->pluck('id');

        $dates = array_filter([
            DB::table('encounters')->whereIn('appointment_id', $appointmentIds)->max('updated_at'),
            DB::table('prescriptions')->whereIn('encounter_id', DB::table('encounters')->whereIn('appointment_id', $appointmentIds)->select('id'))->max('updated_at'),
            DB::table('lab_requests')->where('patient_profile_id', $patient->id)->max('updated_at'),
            DB::table('prescription_uploads')->where('patient_profile_id', $patient->id)->max('updated_at'),
            DB::table('appointments')->where('patient_profile_id', $patient->id)->where('status', 'completed')->max('updated_at'),
            Schema::hasTable('triage_submissions') ? DB::table('triage_submissions')->where('user_id', $patient->user_id)->max('created_at') : null,
        ]);

        if ($dates === []) {
            return null;
        }

        $last = Carbon::parse(max($dates));
        $years = max(1, PrivacySettings::int('privacy_clinical_retention_years'));
        $until = $last->copy()->addYears($years);

        // HPCSA: records of minors are kept until their 21st birthday if later.
        if ($patient->dob) {
            $twentyFirst = Carbon::parse($patient->dob)->addYears(21);
            if ($twentyFirst->gt($until)) {
                $until = $twentyFirst;
            }
        }

        return $until->startOfDay();
    }

    protected function stripClinicalIdentity(int $userId, ?int $patientId): void
    {
        DB::table('users')->where('id', $userId)->update(['name' => 'Deleted user #'.$userId, 'updated_at' => now()]);

        if ($patientId) {
            DB::table('patient_profiles')->where('id', $patientId)->update([
                'dob' => null, 'gender' => null, 'blood_group' => null,
                'allergies' => null, 'chronic_conditions' => null, 'updated_at' => now(),
            ]);
        }
    }

    protected function redactAuditLogs(int $userId, ?int $patientId): void
    {
        $redacted = json_encode(['redacted' => 'POPIA deletion request']);

        DB::table('audit_logs')
            ->where(function ($q) use ($userId, $patientId) {
                $q->where(fn ($w) => $w->where('auditable_type', User::class)->where('auditable_id', $userId));
                if ($patientId) {
                    $q->orWhere(fn ($w) => $w->where('auditable_type', PatientProfile::class)->where('auditable_id', $patientId));
                }
            })
            ->update(['old_values' => $redacted, 'new_values' => $redacted]);
    }

    protected function deleteFile(string $disk, ?string $path): void
    {
        if (! $path || $path === '[removed]') {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
