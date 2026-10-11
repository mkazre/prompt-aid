<?php

namespace App\Services\Privacy;

use App\Models\Appointment;
use App\Models\DataAccessLog;
use App\Models\Encounter;
use App\Models\LabRequest;
use App\Models\LabResult;
use App\Models\PatientDocument;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\TriageSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes data_access_logs rows for opens of special personal (health)
 * information. Fails open: a logging failure is reported but never breaks
 * the page/API response that triggered it.
 *
 * A patient opening their own record is not logged (it isn't a disclosure
 * and would drown the "who accessed my record" view in self-views).
 */
#[\Illuminate\Container\Attributes\Scoped]
class AccessLogger
{
    /** @var array<string, true> de-dupes repeated logs inside one request */
    protected array $seen = [];

    public function log(?Model $subject, string $action = 'view', ?string $context = null, ?User $actor = null): void
    {
        if (! $subject) {
            return;
        }

        try {
            $actor ??= auth()->user();
            $patientProfileId = $this->patientProfileIdFor($subject);

            if ($actor && $patientProfileId && $actor->isPatient()
                && (int) optional($actor->patientProfile)->id === (int) $patientProfileId) {
                return;
            }

            $key = class_basename($subject).':'.$subject->getKey().':'.$action;
            if (isset($this->seen[$key])) {
                return;
            }
            $this->seen[$key] = true;

            DataAccessLog::query()->create([
                'user_id' => $actor?->id,
                'role' => $actor?->role,
                'subject_type' => class_basename($subject),
                'subject_id' => $subject->getKey(),
                'patient_profile_id' => $patientProfileId,
                'action' => in_array($action, ['view', 'export', 'update'], true) ? $action : 'view',
                'context' => mb_substr($context ?? (request()?->route()?->getName() ?: request()?->path() ?: 'cli'), 0, 255),
                'ip' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function patientProfileIdFor(Model $subject): ?int
    {
        $id = match (true) {
            $subject instanceof PatientProfile => $subject->getKey(),
            $subject instanceof PatientDocument,
            $subject instanceof LabRequest,
            $subject instanceof Appointment => $subject->patient_profile_id,
            $subject instanceof LabResult => LabRequest::query()->whereKey($subject->lab_request_id)->value('patient_profile_id'),
            $subject instanceof Encounter => Appointment::query()->whereKey($subject->appointment_id)->value('patient_profile_id'),
            $subject instanceof Prescription => Appointment::query()
                ->whereKey(Encounter::query()->whereKey($subject->encounter_id)->value('appointment_id'))
                ->value('patient_profile_id'),
            $subject instanceof TriageSubmission => $subject->user_id
                ? PatientProfile::query()->where('user_id', $subject->user_id)->value('id')
                : null,
            default => $subject->getAttribute('patient_profile_id'),
        };

        return $id ? (int) $id : null;
    }
}
