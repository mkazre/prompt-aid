<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "How was your visit?" — a 0–10 NPS score + comment for one completed
 * appointment or shuttle ride. Created (and the patient prompted) by
 * App\Services\Support\FeedbackSurveyService.
 */
class FeedbackSurvey extends Model
{
    public const TYPES = [
        'appointment' => 'Appointment',
        'ride' => 'Shuttle ride',
    ];

    protected $fillable = [
        'token', 'user_id', 'subject_type', 'subject_id', 'subject_label', 'score', 'comment',
        'prompted_at', 'responded_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'prompted_at' => 'datetime',
            'responded_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('responded_at')->whereNull('dismissed_at');
    }

    public function scopeResponded(Builder $query): Builder
    {
        return $query->whereNotNull('responded_at');
    }

    public function isAnswered(): bool
    {
        return $this->responded_at !== null;
    }

    /** promoter (9–10), passive (7–8) or detractor (0–6). */
    public function band(): ?string
    {
        if ($this->score === null) {
            return null;
        }

        return $this->score >= 9 ? 'promoter' : ($this->score >= 7 ? 'passive' : 'detractor');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->subject_type] ?? ucfirst((string) $this->subject_type);
    }

    public function webUrl(): string
    {
        return route('account.feedback.show', $this->token);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'type' => $this->subject_type,
            'type_label' => $this->typeLabel(),
            'label' => $this->subject_label,
            'score' => $this->score,
            'comment' => $this->comment,
            'answered' => $this->isAnswered(),
            'prompted_at' => $this->prompted_at?->toIso8601String(),
        ];
    }
}
