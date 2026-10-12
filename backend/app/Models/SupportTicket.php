<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A help & support ticket opened by any signed-in user (patient, doctor,
 * driver, pharmacy, third party). The conversation lives in
 * SupportTicketReply rows; see App\Services\Support\SupportTicketService.
 */
class SupportTicket extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_AWAITING_REPLY = 'awaiting_reply';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    /** status => label. "Awaiting reply" = waiting for the user to answer staff. */
    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_AWAITING_REPLY => 'Awaiting your reply',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_CLOSED => 'Closed',
    ];

    /** Staff-facing wording for the same statuses. */
    public const STAFF_STATUSES = [
        self::STATUS_OPEN => 'Open (needs staff)',
        self::STATUS_AWAITING_REPLY => 'Awaiting user reply',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_CLOSED => 'Closed',
    ];

    public const CATEGORIES = [
        'booking' => 'Booking',
        'payment' => 'Payment',
        'shuttle' => 'Shuttle',
        'technical' => 'Technical',
        'other' => 'Other',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    protected $fillable = [
        'reference', 'user_id', 'subject', 'category', 'status', 'priority',
        'assigned_to', 'last_reply_at', 'last_reply_by', 'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            $ticket->reference ??= static::newReference();
            $ticket->status ??= self::STATUS_OPEN;
            $ticket->priority ??= 'normal';
        });

        static::saving(function (self $ticket) {
            if ($ticket->isDirty('status')) {
                if ($ticket->status === self::STATUS_RESOLVED) {
                    $ticket->resolved_at ??= now();
                }
                if ($ticket->status === self::STATUS_CLOSED) {
                    $ticket->closed_at ??= now();
                }
                if (in_array($ticket->status, [self::STATUS_OPEN, self::STATUS_AWAITING_REPLY], true)) {
                    $ticket->resolved_at = null;
                    $ticket->closed_at = null;
                }
            }
        });
    }

    public static function newReference(): string
    {
        do {
            $ref = 'SUP-'.strtoupper(Str::random(6));
        } while (static::query()->where('reference', $ref)->exists());

        return $ref;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class)->orderBy('id');
    }

    public function scopeNeedsStaff(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    /** Shape shared by the mobile API and the web account. */
    public function toApiArray(bool $withReplies = false, ?User $viewer = null): array
    {
        $data = [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'priority' => $this->priority,
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'last_reply_by' => $this->last_reply_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'can_reply' => ! $this->isClosed(),
        ];

        if ($withReplies) {
            $data['replies'] = $this->replies->map(fn (SupportTicketReply $r) => $r->toApiArray())->values()->all();
        }

        return $data;
    }
}
