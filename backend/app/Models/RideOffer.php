<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A time-boxed offer of one ride to one driver (sequential dispatch). */
class RideOffer extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    /** Withdrawn because the ride was cancelled / assigned elsewhere, or the driver cancelled after accepting. */
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'ride_id', 'driver_profile_id', 'status', 'attempt_no', 'distance_km', 'eta_minutes',
        'offered_at', 'expires_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::PENDING && $this->expires_at && $this->expires_at->isFuture();
    }

    public function secondsLeft(): int
    {
        return $this->expires_at ? max(0, (int) now()->diffInSeconds($this->expires_at, false)) : 0;
    }
}
