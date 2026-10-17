<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

/**
 * POST /api/payments/webhooks/{payfast|paystack|ozow}
 * Every call is logged to payment_events; only verified ones change state.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, PaymentService $payments)
    {
        try {
            $result = $payments->handleWebhook($gateway, $request);
        } catch (\Throwable $e) {
            report($e);

            // 500 → the gateway retries later.
            return response('ERROR', 500);
        }

        if ($result['outcome'] === 'unknown_gateway') {
            return response('Not found', 404);
        }

        return $result['ok'] ? response('OK', 200) : response('Rejected', 400);
    }
}
