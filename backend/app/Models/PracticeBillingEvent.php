<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Timeline / audit trail of a practice subscription (system + admin actions). */
class PracticeBillingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['practice_subscription_id', 'type', 'message', 'user_id', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PracticeSubscription::class, 'practice_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
