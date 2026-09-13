<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabRequest extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SAMPLE_COLLECTED = 'sample_collected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'request_ref', 'doctor_profile_id', 'patient_profile_id', 'third_party_profile_id', 'appointment_id',
        'priority', 'clinical_notes', 'collection_address', 'collection_lat', 'collection_lng',
        'status', 'requested_at', 'accepted_at', 'sample_collected_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
            'sample_collected_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->request_ref ??= 'LAB-'.strtoupper(uniqid());
            $request->requested_at ??= now();
        });
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyProfile::class, 'third_party_profile_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabRequestItem::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }
}
