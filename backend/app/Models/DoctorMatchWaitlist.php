<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Notify me when a doctor in this specialty is online" (one in-app ping). */
class DoctorMatchWaitlist extends Model
{
    public const WAITING = 'waiting';

    public const NOTIFIED = 'notified';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $table = 'doctor_match_waitlist';

    protected $fillable = [
        'patient_profile_id', 'doctor_match_request_id', 'specialty', 'mode', 'lat', 'lng',
        'status', 'notified_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function matchRequest(): BelongsTo
    {
        return $this->belongsTo(DoctorMatchRequest::class, 'doctor_match_request_id');
    }
}
