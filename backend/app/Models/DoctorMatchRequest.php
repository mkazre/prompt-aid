<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * State machine for one "See a doctor now" request (see DoctorMatchService).
 * searching → matched | expired | cancelled. The Appointment it belongs to
 * stays `matching` while searching.
 */
class DoctorMatchRequest extends Model
{
    public const SEARCHING = 'searching';

    public const MATCHED = 'matched';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'appointment_id', 'patient_profile_id', 'specialty', 'mode', 'lat', 'lng', 'status',
        'outcome_reason', 'base_fee', 'multiplier', 'price', 'price_breakdown', 'attempts',
        'expires_at', 'matched_at', 'doctor_profile_id', 'clinic_id', 'invoice_id',
        'cancelled_at', 'cancel_fee', 'radius_km', 'triage_submission_id',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'base_fee' => 'float',
            'multiplier' => 'float',
            'price' => 'float',
            'cancel_fee' => 'float',
            'price_breakdown' => 'array',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'matched_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::SEARCHING;
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
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

    public function triageSubmission(): BelongsTo
    {
        return $this->belongsTo(TriageSubmission::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(DoctorMatchOffer::class)->orderBy('id');
    }

    public function currentOffer(): HasOne
    {
        return $this->hasOne(DoctorMatchOffer::class)->where('status', DoctorMatchOffer::OFFERED)->latestOfMany();
    }
}
