<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Admin-defined recurring plan (billed through PayFast subscriptions). */
class SubscriptionPlan extends Model
{
    public const SCOPES = [
        'shuttle_pass' => 'Shuttle pass (e.g. dialysis trips)',
        'chronic_medication' => 'Chronic medication monthly',
        'telehealth_membership' => 'Telehealth / doctor membership',
        'general' => 'General membership',
    ];

    /** Our frequency key => PayFast `frequency` code. */
    public const FREQUENCIES = [
        'weekly' => 2,
        'monthly' => 3,
        'quarterly' => 4,
        'biannually' => 5,
        'annually' => 6,
    ];

    public const FREQUENCY_LABELS = [
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Every 3 months',
        'biannually' => 'Every 6 months',
        'annually' => 'Yearly',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'scope', 'amount', 'frequency', 'cycles',
        'discount_percent', 'benefits', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'discount_percent' => 'float',
            'benefits' => 'array',
            'is_active' => 'boolean',
            'cycles' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $plan) {
            if (! $plan->slug) {
                $plan->slug = Str::slug($plan->name).'-'.Str::lower(Str::random(4));
            }
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCY_LABELS[$this->frequency] ?? ucfirst((string) $this->frequency);
    }

    /** Next billing date after $from for this frequency. */
    public static function advance(string $frequency, \Carbon\CarbonInterface $from): \Carbon\CarbonInterface
    {
        $d = \Carbon\Carbon::instance($from);

        return match ($frequency) {
            'weekly' => $d->addWeek(),
            'quarterly' => $d->addMonthsNoOverflow(3),
            'biannually' => $d->addMonthsNoOverflow(6),
            'annually' => $d->addYear(),
            default => $d->addMonthNoOverflow(),
        };
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'scope' => $this->scope,
            'amount' => (float) $this->amount,
            'frequency' => $this->frequency,
            'frequency_label' => $this->frequencyLabel(),
            'cycles' => (int) $this->cycles,
            'discount_percent' => $this->discount_percent ? (float) $this->discount_percent : null,
            'benefits' => array_values(array_filter((array) ($this->benefits ?? []), 'is_string')),
        ];
    }
}
