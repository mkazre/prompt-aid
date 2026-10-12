<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Support\CommerceSettings;
use RuntimeException;

/**
 * Validates and redeems promo codes. The discount applies to the basket
 * subtotal (never the delivery fee) and is capped at the subtotal.
 *
 * Shuttle rides are not supported yet: a ride's fare is locked at quote
 * time and then re-priced at drop-off (waiting time, route changes, see
 * PricingService::finalFare), so there is no single clean point to apply a
 * discount. `applies_to` = shuttle/both is stored for later, but only the
 * pharmacy checkout redeems codes today.
 */
class PromoCodeService
{
    /**
     * Check a code without redeeming it.
     *
     * @return array{promo: PromoCode, discount: float, subtotal: float}
     *
     * @throws RuntimeException with a patient-friendly message
     */
    public function evaluate(string $code, ?User $user, string $context, float $subtotal, bool $lock = false): array
    {
        if (! CommerceSettings::bool('promo_codes_enabled')) {
            throw new RuntimeException('Promo codes are not available right now.');
        }

        $normalized = PromoCode::normalize($code);
        $query = PromoCode::query()->where('code', $normalized);
        if ($lock) {
            $query->lockForUpdate();
        }
        $promo = $normalized === '' ? null : $query->first();

        if (! $promo || ! $promo->is_active) {
            throw new RuntimeException('That promo code is not valid.');
        }
        if (! $promo->appliesTo($context)) {
            throw new RuntimeException($context === PromoCode::CONTEXT_PHARMACY
                ? 'That promo code cannot be used on pharmacy orders.'
                : 'That promo code cannot be used here.');
        }
        if ($promo->starts_at && $promo->starts_at->isFuture()) {
            throw new RuntimeException('That promo code is not active yet.');
        }
        if ($promo->ends_at && $promo->ends_at->isPast()) {
            throw new RuntimeException('That promo code has expired.');
        }
        if ($promo->min_spend !== null && $subtotal < (float) $promo->min_spend) {
            throw new RuntimeException('Spend at least R'.number_format((float) $promo->min_spend, 2).' to use this code.');
        }
        if ($promo->max_uses !== null && $promo->redemptions()->count() >= $promo->max_uses) {
            throw new RuntimeException('That promo code has been fully redeemed.');
        }
        if ($promo->max_uses_per_user !== null && $user
            && $promo->redemptions()->where('user_id', $user->id)->count() >= $promo->max_uses_per_user) {
            throw new RuntimeException('You have already used this promo code.');
        }

        $discount = $promo->discountFor($subtotal);
        if ($discount <= 0) {
            throw new RuntimeException('Add items to your basket to use this code.');
        }

        return ['promo' => $promo, 'discount' => $discount, 'subtotal' => round($subtotal, 2)];
    }

    /**
     * Basket subtotal from the same cart lines the checkout accepts, priced
     * from the database (never trusts client prices).
     *
     * @param  array<int, array{product_id: int, qty: int}>  $items
     */
    public function pharmacySubtotal(Pharmacy $pharmacy, array $items): float
    {
        $subtotal = 0.0;
        foreach ($items as $line) {
            $qty = max(0, (int) ($line['qty'] ?? 0));
            if ($qty === 0) {
                continue;
            }
            $price = Product::query()->where('pharmacy_id', $pharmacy->id)->whereKey((int) ($line['product_id'] ?? 0))->value('price');
            $subtotal += (float) $price * $qty;
        }

        return round($subtotal, 2);
    }

    /** Record a redemption (call inside the checkout transaction). */
    public function redeem(PromoCode $promo, ?User $user, string $context, float $subtotal, float $discount, ?Order $order = null): PromoRedemption
    {
        return PromoRedemption::query()->create([
            'promo_code_id' => $promo->id,
            'user_id' => $user?->id,
            'order_id' => $order?->id,
            'context' => $context,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
        ]);
    }
}
