<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One trip audio recording (safety evidence) made by the ride's patient or
 * driver. Audio is encrypted at rest; see App\Services\Safety\RideRecordingService.
 *
 * @property array|null $segments manifest: [{seq, file, bytes, sha256, duration_ms, at}]
 */
class RideRecording extends Model
{
    use SoftDeletes;

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    public const LAYOUT_STREAM = 'stream';

    public const LAYOUT_SEGMENTS = 'segments';

    protected $fillable = [
        'ride_id', 'user_id', 'role', 'source', 'layout', 'disk', 'path', 'mime', 'size_bytes',
        'duration_seconds', 'chunk_count', 'segments', 'started_at', 'ended_at', 'last_chunk_at',
        'sha256', 'status', 'end_reason', 'consent_at', 'expires_at', 'legal_hold', 'hold_reason',
        'hold_source', 'held_by', 'held_at', 'hold_released_at', 'hold_released_by',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_chunk_at' => 'datetime',
            'consent_at' => 'datetime',
            'expires_at' => 'datetime',
            'held_at' => 'datetime',
            'hold_released_at' => 'datetime',
            'legal_hold' => 'boolean',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'chunk_count' => 'integer',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(RideRecordingAccessLog::class)->latest('id');
    }

    /** @return array<int, array> manifest sorted by sequence */
    public function orderedSegments(): array
    {
        $segs = is_array($this->segments) ? array_values($this->segments) : [];
        usort($segs, fn ($a, $b) => ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0));

        return $segs;
    }

    /** True while the recorder is (or very recently was) sending audio. */
    public function isLive(): bool
    {
        if ($this->status !== self::STATUS_UPLOADING) {
            return false;
        }
        $last = $this->last_chunk_at ?? $this->started_at ?? $this->created_at;

        return $last !== null && $last->gt(now()->subSeconds(max(90, \App\Support\RideRecordingSettings::chunkSeconds() * 3)));
    }

    public function fileExtension(): string
    {
        return match ($this->mime) {
            'audio/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/aac' => 'aac',
            default => 'm4a',
        };
    }

    /** Content-Type to serve (browsers and AVPlayer prefer audio/mp4 for .m4a). */
    public function servedMime(): string
    {
        return $this->mime === 'audio/m4a' ? 'audio/mp4' : $this->mime;
    }

    public function durationLabel(): string
    {
        $s = (int) $this->duration_seconds;

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
