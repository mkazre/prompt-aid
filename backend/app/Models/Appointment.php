<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const MODE_IN_PERSON = 'in_person';

    public const MODE_VIDEO = 'video';

    protected $fillable = [
        'booking_ref', 'patient_profile_id', 'doctor_profile_id', 'clinic_id', 'service_id',
        'date', 'start_time', 'end_time', 'visit_type', 'status', 'reason', 'cancel_reason', 'ride_requested',
        'mode', 'meet_url', 'meet_event_id', 'meet_created_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'ride_requested' => 'boolean',
            'meet_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $appointment) {
            $appointment->booking_ref ??= 'APT-'.strtoupper(uniqid());
        });
    }

    public function isVideo(): bool
    {
        return $this->mode === self::MODE_VIDEO;
    }

    /**
     * Whether the video Join button should be shown right now — 10 minutes
     * before start through 30 minutes after the scheduled end.
     */
    public function canJoinVideoNow(): bool
    {
        if (! $this->isVideo() || ! $this->meet_url) {
            return false;
        }

        $start = \Carbon\Carbon::parse("{$this->date->toDateString()} {$this->start_time}");
        $end = \Carbon\Carbon::parse("{$this->date->toDateString()} {$this->end_time}");

        return now()->between($start->clone()->subMinutes(10), $end->clone()->addMinutes(30));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function encounter(): HasOne
    {
        return $this->hasOne(Encounter::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function ride(): HasOne
    {
        return $this->hasOne(Ride::class);
    }
}
