<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One billing period charge (receipt) of a practice subscription. Amounts in cents. */
class PracticeInvoice extends Model
{
    public const STATUSES = [
        'paid' => 'Paid',
        'failed' => 'Failed',
        'waived' => 'Waived',
        'void' => 'Void',
    ];

    public const METHODS = [
        'payfast' => 'PayFast (card)',
        'mock' => 'Test payment',
        'eft' => 'EFT (marked paid by admin)',
        'manual' => 'Manual (admin)',
        'comp' => 'Waived (admin)',
    ];

    protected $fillable = [
        'practice_subscription_id', 'number', 'plan_name', 'description', 'period_start', 'period_end', 'seats',
        'amount_cents', 'status', 'method', 'payment_intent_id', 'gateway_ref', 'paid_at', 'recorded_by', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
            'amount_cents' => 'integer',
            'seats' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $inv) {
            if (str_starts_with((string) $inv->number, 'TMP-')) {
                $inv->forceFill(['number' => 'PB-'.now()->format('Ym').'-'.str_pad((string) $inv->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PracticeSubscription::class, 'practice_subscription_id');
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function amountLabel(): string
    {
        return Money::format((int) $this->amount_cents);
    }
}
