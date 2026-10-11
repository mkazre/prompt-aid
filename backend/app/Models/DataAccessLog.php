<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Insert-only log of record opens of special personal (health) information. */
class DataAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'role', 'subject_type', 'subject_id', 'patient_profile_id',
        'action', 'context', 'ip', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    /** Human label for the subject, e.g. "Encounter". */
    public function subjectLabel(): string
    {
        return match ($this->subject_type) {
            'PatientProfile' => 'Patient profile',
            'Encounter' => 'Consultation notes',
            'Prescription' => 'Prescription',
            'LabRequest', 'LabResult' => 'Lab request / results',
            'PatientDocument' => 'Uploaded document',
            'TriageSubmission' => 'Triage assessment',
            default => (string) $this->subject_type,
        };
    }
}
