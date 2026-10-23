<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consult hub record for one appointment (see App\Services\Consults\ConsultHubService).
 *
 * channels: list of segments
 *   {channel: video|voice|chat|phone|whatsapp, started_at, last_at, ended_at, seconds}
 */
class ConsultSession extends Model
{
    public const CHANNELS = ['video', 'voice', 'chat', 'phone', 'whatsapp'];

    /** What the doctor can pick when ending the consult. */
    public const OUTCOMES = [
        'completed' => 'Consult completed',
        'escalated' => 'Escalated — advised ambulance / emergency care',
        'patient_no_show' => 'Patient did not join / answer',
        'no_answer' => 'Could not reach the patient (call back)',
        'failed' => 'Technical problem — could not consult',
        'doctor_cancelled' => 'I could not take this consult',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'opened_at' => 'datetime',
            'connected_at' => 'datetime',
            'doctor_seen_at' => 'datetime',
            'patient_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'connected_seconds' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function isEnded(): bool
    {
        return $this->ended_at !== null;
    }

    public static function channelLabel(?string $channel): string
    {
        return match ($channel) {
            'video' => 'Video call',
            'voice' => 'Voice call (in-app)',
            'chat' => 'Chat',
            'phone' => 'Phone call',
            'whatsapp' => 'WhatsApp call',
            default => '—',
        };
    }
}
