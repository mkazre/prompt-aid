<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence chain: who played/downloaded/held/released/deleted a trip recording, and when. */
class RideRecordingAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ride_recording_id', 'user_id', 'actor_role', 'action', 'detail', 'ip', 'user_agent'];

    public function recording(): BelongsTo
    {
        return $this->belongsTo(RideRecording::class, 'ride_recording_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
