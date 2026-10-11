<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sequential offer of a DoctorMatchRequest to a single doctor. */
class DoctorMatchOffer extends Model
{
    public const OFFERED = 'offered';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'doctor_match_request_id', 'appointment_id', 'doctor_profile_id', 'status',
        'distance_km', 'offered_at', 'expires_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'float',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function secondsLeft(): int
    {
        return $this->expires_at && $this->expires_at->isFuture() ? (int) ceil(abs(now()->diffInSeconds($this->expires_at))) : 0;
    }

    public function matchRequest(): BelongsTo
    {
        return $this->belongsTo(DoctorMatchRequest::class, 'doctor_match_request_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }
}
