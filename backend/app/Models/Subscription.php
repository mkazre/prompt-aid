<?php

namespace App\Models;

use App\Contracts\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A user's recurring subscription to a plan, or (kind = card_token) a
 * PayFast tokenisation agreement used for later ad-hoc charges.
 */
class Subscription extends Model implements Payable
{
    public const KIND_RECURRING = 'recurring';

    public const KIND_CARD_TOKEN = 'card_token';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending first payment',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_PAUSED => 'Paused',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_FAILED => 'Failed',
    ];

    protected $fillable = [
        'uuid', 'user_id', 'subscription_plan_id', 'kind', 'gateway', 'token', 'status', 'amount',
        'frequency', 'cycles', 'cycles_completed', 'subject_type', 'subject_id', 'started_at',
        'next_billing_at', 'last_paid_at', 'paused_at', 'cancelled_at', 'cancel_reason', 'metadata',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'next_billing_at' => 'datetime',
            'last_paid_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            $s->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function intents(): MorphMany
    {
        return $this->morphMany(PaymentIntent::class, 'payable');
    }

    public function getTotal(): float
    {
        return (float) $this->amount;
    }

    public function getPayableReference(): string
    {
        return 'SUB-'.$this->id;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'gateway' => $this->gateway,
            'amount' => (float) $this->amount,
            'frequency' => $this->frequency,
            'frequency_label' => SubscriptionPlan::FREQUENCY_LABELS[$this->frequency] ?? $this->frequency,
            'cycles' => (int) $this->cycles,
            'cycles_completed' => (int) $this->cycles_completed,
            'next_billing_at' => $this->next_billing_at?->toIso8601String(),
            'last_paid_at' => $this->last_paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'plan' => $this->plan?->toApi(),
            'can_pause' => $this->status === self::STATUS_ACTIVE && $this->kind === self::KIND_RECURRING,
            'can_resume' => $this->status === self::STATUS_PAUSED,
            'can_cancel' => in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_PENDING], true),
        ];
    }
}
