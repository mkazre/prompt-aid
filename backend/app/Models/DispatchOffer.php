<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchOffer extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    /** Another provider accepted first. */
    public const TAKEN = 'taken';

    /** Withdrawn (request cancelled or assigned by an admin). */
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'dispatch_job_id', 'provider_user_id', 'status', 'distance_km', 'offered_at', 'expires_at', 'responded_at', 'decline_reason',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(DispatchJob::class, 'dispatch_job_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    public function secondsLeft(): int
    {
        return $this->expires_at ? max(0, (int) now()->diffInSeconds($this->expires_at, false)) : 0;
    }
}
