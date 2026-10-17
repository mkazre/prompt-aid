<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A request dispatched to providers by the unified engine
 * (App\Services\Dispatch\DispatchService): appointment with a specific
 * doctor, "any doctor later/tomorrow", pharmacy orders, script reviews and
 * lab requests. Shuttle rides and "see a doctor now" keep their own offer
 * tables (ride_offers / doctor_match_offers) with the same semantics.
 */
class DispatchJob extends Model
{
    public const SEARCHING = 'searching';

    public const ACCEPTED = 'accepted';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** Waiting for an admin to assign (manual mode). */
    public const MANUAL = 'manual';

    public const OPEN = [self::SEARCHING, self::MANUAL];

    protected $fillable = [
        'service', 'subject_type', 'subject_id', 'patient_user_id', 'mode', 'status', 'lat', 'lng', 'radius_km',
        'capability', 'window_from', 'window_to', 'target_user_id', 'accepted_by_user_id', 'accepted_at',
        'expires_at', 'offers_count', 'closed_reason', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(DispatchOffer::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function patientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
