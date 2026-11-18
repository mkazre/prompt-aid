<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A platform plan practices (clinics / independent doctors) pay PromptAid for.
 * Not the patient SubscriptionPlan. Prices are integer cents.
 */
class PracticePlan extends Model
{
    public const BASIS_FLAT = 'flat';

    public const BASIS_PER_SEAT = 'per_seat';

    public const BASES = [
        self::BASIS_FLAT => 'Per practice (flat)',
        self::BASIS_PER_SEAT => 'Per doctor seat',
    ];

    public const AUDIENCES = [
        'any' => 'Clinics and independent doctors',
        'clinic' => 'Clinics only',
        'doctor' => 'Independent doctors only',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'audience', 'price_cents', 'annual_price_cents', 'billing_basis',
        'included_seats', 'extra_seat_cents', 'max_doctors', 'receives_instant_requests', 'listed_in_search',
        'accepts_bookings', 'trial_days', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'annual_price_cents' => 'integer',
            'included_seats' => 'integer',
            'extra_seat_cents' => 'integer',
            'max_doctors' => 'integer',
            'trial_days' => 'integer',
            'receives_instant_requests' => 'boolean',
            'listed_in_search' => 'boolean',
            'accepts_bookings' => 'boolean',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $plan) {
            if (! $plan->slug) {
                $plan->slug = Str::slug($plan->name).'-'.Str::lower(Str::random(4));
            }
            $plan->included_seats = max(1, (int) $plan->included_seats);
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PracticeSubscription::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Plans a subscriber type ('clinic' | 'doctor') may choose. */
    public function scopeFor(Builder $q, string $subscriberType): Builder
    {
        return $q->whereIn('audience', ['any', $subscriberType]);
    }

    public function hasAnnual(): bool
    {
        return $this->annual_price_cents !== null && $this->annual_price_cents > 0;
    }

    public function isPerSeat(): bool
    {
        return $this->billing_basis === self::BASIS_PER_SEAT;
    }

    /** Seats billed for a practice with $doctors doctors (never below the included seats). */
    public function seatsFor(int $doctors): int
    {
        return $this->isPerSeat() ? max((int) $this->included_seats, $doctors, 1) : 1;
    }

    /**
     * Recurring amount (cents) for a cycle and seat count.
     * Per-seat plans: base price covers the included seats; every extra seat adds
     * extra_seat_cents per month (×12 on the annual cycle).
     */
    public function priceFor(string $cycle, int $seats): int
    {
        $annual = $cycle === 'annual' && $this->hasAnnual();
        $base = $annual ? (int) $this->annual_price_cents : (int) $this->price_cents;
        $extra = $this->isPerSeat() ? max(0, $seats - (int) $this->included_seats) * (int) $this->extra_seat_cents : 0;

        return $base + ($annual ? $extra * 12 : $extra);
    }

    public function priceLabel(): string
    {
        $s = Money::format((int) $this->price_cents).' / month';
        if ($this->isPerSeat()) {
            $s .= ' incl. '.$this->included_seats.' doctor'.($this->included_seats === 1 ? '' : 's')
                .($this->extra_seat_cents > 0 ? ', + '.Money::format((int) $this->extra_seat_cents).' per extra doctor' : '');
        }
        if ($this->hasAnnual()) {
            $s .= ' · or '.Money::format((int) $this->annual_price_cents).' / year';
        }

        return $s;
    }
}
