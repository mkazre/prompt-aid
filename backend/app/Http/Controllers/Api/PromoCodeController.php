<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PromoCode;
use App\Services\Commerce\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/promo-codes/validate — checks a code against the basket
 * (priced server-side from pharmacy_id + items) and returns the discount.
 * Nothing is redeemed here; the checkout re-validates and records it.
 * The website's equivalent (PromoCodeWebController) shares respond().
 */
class PromoCodeController extends Controller
{
    public function validateCode(Request $request, PromoCodeService $promos): JsonResponse
    {
        return static::respond($request, $promos);
    }

    public static function respond(Request $request, PromoCodeService $promos): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'context' => ['nullable', 'in:pharmacy,shuttle'],
            'pharmacy_id' => ['nullable', 'integer', 'exists:pharmacies,id'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:0'],
            'qty' => ['nullable', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $context = $data['context'] ?? PromoCode::CONTEXT_PHARMACY;
        $pharmacy = isset($data['pharmacy_id']) ? Pharmacy::find($data['pharmacy_id']) : null;

        // Cart lines: the app sends items[], the website form sends qty[productId].
        $items = $data['items'] ?? collect($data['qty'] ?? [])
            ->map(fn ($qty, $productId) => ['product_id' => (int) $productId, 'qty' => (int) $qty])
            ->values()->all();

        $subtotal = $pharmacy && $items
            ? $promos->pharmacySubtotal($pharmacy, $items)
            : (float) ($data['subtotal'] ?? 0);

        try {
            $result = $promos->evaluate($data['code'], $request->user(), $context, $subtotal);
        } catch (RuntimeException $e) {
            return response()->json(['valid' => false, 'message' => $e->getMessage()], 422);
        }

        $promo = $result['promo'];
        $deliveryFee = $pharmacy ? (float) $pharmacy->delivery_fee : null;

        return response()->json([
            'valid' => true,
            'code' => $promo->code,
            'label' => $promo->label(),
            'description' => $promo->description,
            'subtotal' => $result['subtotal'],
            'discount' => $result['discount'],
            'delivery_fee' => $deliveryFee,
            'total' => $deliveryFee !== null ? round($result['subtotal'] - $result['discount'] + $deliveryFee, 2) : null,
            'message' => $promo->label().' applied — you save R'.number_format($result['discount'], 2).'.',
        ]);
    }
}
