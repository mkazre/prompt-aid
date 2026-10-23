<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One online checkout attempt for a payable (Invoice, Order, RideCharge or
 * Subscription). Only a VERIFIED gateway webhook (or a server-side status
 * query) ever moves it to `paid` — the client "return" is never trusted.
 */
class PaymentIntent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    /** Hold: funds secured upfront, not yet captured (see ConsultPaymentService). */
    public const STATUS_AUTHORIZED = 'authorized';

    /** Hold given back in full (no match, doctor no-show, ride cancelled free). */
    public const STATUS_RELEASED = 'released';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_PAID => 'Paid',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_REFUNDED => 'Refunded',
        self::STATUS_AUTHORIZED => 'Authorised (held)',
        self::STATUS_RELEASED => 'Released',
    ];

    /** Morph aliases the API accepts as `payable_type`. */
    public const PAYABLE_TYPES = [
        'invoice' => Invoice::class,
        'order' => Order::class,
        'ride_charge' => RideCharge::class,
        'subscription' => Subscription::class,
        'consult' => DoctorMatchRequest::class,
        'ride' => Ride::class,
    ];

    protected $fillable = [
        'uuid', 'user_id', 'payable_type', 'payable_id', 'amount', 'currency', 'gateway', 'channel',
        'status', 'reference', 'gateway_ref', 'gateway_token', 'description', 'idempotency_key',
        'embed_token_hash', 'embed_expires_at', 'metadata', 'refunded_amount', 'failure_reason',
        'paid_at', 'refunded_at', 'last_checked_at',
        'capture_mode', 'captured_amount', 'authorized_at', 'captured_at', 'released_at',
    ];

    protected $hidden = ['embed_token_hash', 'gateway_token'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'refunded_amount' => 'float',
            'captured_amount' => 'float',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'released_at' => 'datetime',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'embed_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $intent) {
            $intent->uuid ??= (string) Str::uuid();
            $intent->reference ??= 'PA'.now()->format('ymd').strtoupper(Str::random(8));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REFUNDED, self::STATUS_AUTHORIZED, self::STATUS_RELEASED], true);
    }

    public function payableAlias(): ?string
    {
        $alias = array_search($this->payable_type, self::PAYABLE_TYPES, true);

        return $alias === false ? null : $alias;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata ?? [], $key, $default);
    }
}
