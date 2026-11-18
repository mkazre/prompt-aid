<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One in-app voice / video call (App\Services\PracticeCalls\PracticeCallService for
 * practice ↔ patient calls, App\Services\PracticeCalls\CallLogService for consult-hub
 * calls). participants: [{user_id, role, name, event: joined|left|accepted|declined|ended, at}].
 */
class CallLog extends Model
{
    public const RINGING = 'ringing';

    public const CONNECTED = 'connected';

    public const ENDED = 'ended';

    public const DECLINED = 'declined';

    public const MISSED = 'missed';

    public const CANCELLED = 'cancelled';

    public const OPEN = [self::RINGING, self::CONNECTED];

    public const SOURCE_PRACTICE = 'practice_call';

    public const SOURCE_CONSULT = 'consult_hub';

    public const OUTCOMES = [
        'booked' => 'Appointment booked',
        'no_booking' => 'Call ended without a booking',
        'patient_declined' => 'Patient declined the call',
        'practice_declined' => 'Practice declined the call',
        'missed' => 'Not answered',
        'requeued' => 'Returned to the queue for another practice',
        'completed' => 'Consult completed',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'meta' => 'array',
            'ringing_at' => 'datetime',
            'practice_accepted_at' => 'datetime',
            'patient_accepted_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'ring_attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->uuid ??= (string) Str::uuid();
        });
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function matchRequest(): BelongsTo
    {
        return $this->belongsTo(DoctorMatchRequest::class, 'doctor_match_request_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function patientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }

    public function practiceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'practice_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(CallRecording::class)->orderBy('id');
    }

    public static function outcomeLabel(?string $outcome): string
    {
        return self::OUTCOMES[$outcome] ?? ($outcome ? ucfirst(str_replace('_', ' ', $outcome)) : '—');
    }
}
