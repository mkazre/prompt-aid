<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory, \App\Concerns\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    // Awaiting a doctor to be auto/self-matched on the on-demand video/in-person
    // path; doctor_profile_id and clinic_id are still null in this state.
    public const STATUS_MATCHING = 'matching';

    public const MODE_IN_PERSON = 'in_person';

    public const MODE_VIDEO = 'video';

    protected $fillable = [
        'booking_ref', 'patient_profile_id', 'doctor_profile_id', 'clinic_id', 'service_id',
        'date', 'start_time', 'end_time', 'visit_type', 'status', 'reason', 'cancel_reason', 'ride_requested',
        'mode', 'meet_url', 'meet_event_id', 'meet_created_at', 'requested_specialty',
        'video_room', 'video_ended_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'ride_requested' => 'boolean',
            'meet_created_at' => 'datetime',
            'video_ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $appointment) {
            $appointment->booking_ref ??= 'APT-'.strtoupper(uniqid());
        });

        // Every video consult gets its own unguessable room name, generated
        // server-side (see App\Services\Video\VideoRoomService). Existing
        // rows get one on save; new rows right after insert, once the id
        // is known.
        static::saving(function (self $appointment) {
            if ($appointment->exists && $appointment->isVideo() && ! $appointment->video_room) {
                $appointment->video_room = self::generateVideoRoom($appointment->id);
            }
        });

        static::created(function (self $appointment) {
            if ($appointment->isVideo() && ! $appointment->video_room) {
                $appointment->video_room = self::generateVideoRoom($appointment->id);
                $appointment->saveQuietly();
            }
        });
    }

    /** e.g. promptaid-1234-9f86d081884c7d65 — the 16 random hex chars carry the unguessability. */
    public static function generateVideoRoom(int $id): string
    {
        return 'promptaid-'.$id.'-'.bin2hex(random_bytes(8));
    }

    public function isVideo(): bool
    {
        // Slot bookings express video as visit_type=telemed without setting mode.
        return $this->mode === self::MODE_VIDEO || $this->visit_type === 'telemed';
    }

    public function videoWindowStart(): ?\Carbon\Carbon
    {
        if (! $this->date || ! $this->start_time) {
            return null;
        }

        return \Carbon\Carbon::parse("{$this->date->toDateString()} {$this->start_time}")->subMinutes(10);
    }

    public function videoWindowEnd(): ?\Carbon\Carbon
    {
        if (! $this->date || ! ($this->end_time ?: $this->start_time)) {
            return null;
        }

        return \Carbon\Carbon::parse("{$this->date->toDateString()} ".($this->end_time ?: $this->start_time))->addMinutes(30);
    }

    /**
     * Whether the video Join button should be shown right now — 10 minutes
     * before start through 30 minutes after the scheduled end.
     */
    public function canJoinVideoNow(): bool
    {
        if (! $this->isVideo() || ! $this->doctor_profile_id) {
            return false;
        }

        if (in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_NO_SHOW, self::STATUS_MATCHING], true)) {
            return false;
        }

        $start = $this->videoWindowStart();
        $end = $this->videoWindowEnd();

        return $start && $end && now()->between($start, $end);
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
