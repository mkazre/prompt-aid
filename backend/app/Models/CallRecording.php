<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A call recording stored on Bunny.net Storage or the private local disk (App\Services\PracticeCalls\RecordingStorage). */
class CallRecording extends Model
{
    public const RECEIVING = 'receiving';

    public const PROCESSING = 'processing';

    public const STORED = 'stored';

    public const FAILED = 'failed';

    public const DELETED = 'deleted';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'chunk_count' => 'integer',
            'legal_hold' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'retention_until' => 'datetime',
            'held_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(CallRecordingAccessLog::class)->latest('id');
    }

    public function isPlayable(): bool
    {
        return $this->status === self::STORED && $this->path !== null && $this->deleted_at === null;
    }
}
