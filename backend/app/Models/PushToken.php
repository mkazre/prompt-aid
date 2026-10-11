<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Expo push token for one of a user's devices (see App\Services\Notifications\ExpoPush). */
class PushToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'device_name', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
