<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const TYPES = [
        self::TYPE_PERCENT => 'Percent off',
        self::TYPE_FIXED => 'Fixed amount off (ZAR)',
    ];

    public const CONTEXT_PHARMACY = 'pharmacy';

    public const CONTEXT_SHUTTLE = 'shuttle';

    public const APPLIES_TO = [
        'pharmacy' => 'Pharmacy orders',
        'shuttle' => 'Shuttle rides',
        'both' => 'Both',
    ];

    protected $fillable = [
        'code', 'description', 'type', 'value', 'min_spend', 'max_uses', 'max_uses_per_user',
        'starts_at', 'ends_at', 'applies_to', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'max_uses' => 'integer',
            'max_uses_per_user' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $promo) {
            $promo->code = self::normalize((string) $promo->code);
        });
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    public static function findByCode(string $code): ?self
    {
        $code = self::normalize($code);

        return $code === '' ? null : static::query()->where('code', $code)->first();
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    public function appliesTo(string $context): bool
    {
        return $this->applies_to === 'both' || $this->applies_to === $context;
    }

    /** Discount for a given subtotal (never more than the subtotal). */
    public function discountFor(float $subtotal): float
    {
        $discount = $this->type === self::TYPE_PERCENT
            ? $subtotal * min(100.0, max(0.0, (float) $this->value)) / 100
            : max(0.0, (float) $this->value);

        return round(min($discount, max(0.0, $subtotal)), 2);
    }

    public function label(): string
    {
        return $this->type === self::TYPE_PERCENT
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off'
            : 'R'.number_format((float) $this->value, 2).' off';
    }
}
