<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A practice's platform subscription (one row per practice: a clinic, or an
 * independent doctor without a clinic). Rows are reused when a practice
 * re-subscribes; history lives in practice_invoices + practice_billing_events.
 *
 * The request gate is App\Services\Billing\PracticeSubscription (same short
 * name, different namespace); lifecycle is App\Services\Billing\PracticeBilling.
 */
class PracticeSubscription extends Model
{
    public const TYPE_CLINIC = 'clinic';

    public const TYPE_DOCTOR = 'doctor';

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_GRACE = 'grace';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    /** Chose a plan without a trial, first payment not received yet. */
    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUSES = [
        self::STATUS_TRIALING => 'Trial',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_PAST_DUE => 'Payment failed',
        self::STATUS_GRACE => 'Grace period',
        self::STATUS_SUSPENDED => 'Suspended',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_INCOMPLETE => 'Awaiting first payment',
    ];

    public const STATUS_COLORS = [
        self::STATUS_TRIALING => 'info',
        self::STATUS_ACTIVE => 'success',
        self::STATUS_PAST_DUE => 'warning',
        self::STATUS_GRACE => 'warning',
        self::STATUS_SUSPENDED => 'danger',
        self::STATUS_CANCELLED => 'gray',
        self::STATUS_INCOMPLETE => 'gray',
    ];

    public const CYCLES = ['monthly' => 'Monthly', 'annual' => 'Yearly'];

    protected $fillable = [
        'subscriber_type', 'subscriber_id', 'practice_plan_id', 'status', 'billing_cycle', 'seats', 'amount_cents',
        'gateway', 'token', 'first_intent_id', 'pending_intent_id', 'pending_checkout',
        'trial_ends_at', 'current_period_start', 'current_period_end', 'next_billing_at', 'last_paid_at',
        'past_due_at', 'grace_ends_at', 'suspended_at', 'cancelled_at', 'cancel_at_period_end', 'cancel_reason',
        'comped_until', 'comp_reason', 'last_reminder_at', 'reminders_sent', 'notes',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'amount_cents' => 'integer',
            'pending_checkout' => 'array',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_billing_at' => 'datetime',
            'last_paid_at' => 'datetime',
            'past_due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'comped_until' => 'datetime',
            'last_reminder_at' => 'datetime',
            'reminders_sent' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PracticePlan::class, 'practice_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PracticeInvoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PracticeBillingEvent::class);
    }

    public function firstIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'first_intent_id');
    }

    public function pendingIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'pending_intent_id');
    }

    public function subscriber(): Clinic|DoctorProfile|null
    {
        return match ($this->subscriber_type) {
            self::TYPE_CLINIC => Clinic::query()->find($this->subscriber_id),
            self::TYPE_DOCTOR => DoctorProfile::query()->with('user')->find($this->subscriber_id),
            default => null,
        };
    }

    public function subscriberLabel(): string
    {
        $s = $this->subscriber();

        return match (true) {
            $s instanceof Clinic => $s->name,
            $s instanceof DoctorProfile => 'Dr '.preg_replace('/^Dr\.?\s+/i', '', (string) ($s->user?->name ?? '#'.$s->id)).' (independent)',
            default => ucfirst((string) $this->subscriber_type).' #'.$this->subscriber_id,
        };
    }

    /** Users who manage billing for this practice (clinic admin / the doctor). */
    public function billingContacts(): array
    {
        $s = $this->subscriber();
        $u = match (true) {
            $s instanceof Clinic => $s->admin,
            $s instanceof DoctorProfile => $s->user,
            default => null,
        };

        return $u ? [$u] : [];
    }

    public function isComped(): bool
    {
        return $this->comped_until !== null && $this->comped_until->isFuture();
    }

    /** Date up to which the practice has paid / is covered (trial, period or waiver). */
    public function coveredUntil(): ?\Carbon\CarbonInterface
    {
        $dates = array_filter([
            $this->status === self::STATUS_TRIALING ? $this->trial_ends_at : null,
            $this->current_period_end,
            $this->comped_until,
        ]);
        if ($dates === []) {
            return null;
        }
        usort($dates, fn ($a, $b) => $b <=> $a);

        return $dates[0];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function amountLabel(): string
    {
        return Money::format((int) $this->amount_cents).' / '.($this->billing_cycle === 'annual' ? 'year' : 'month');
    }
}
