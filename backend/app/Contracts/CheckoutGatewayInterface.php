<?php

namespace App\Contracts;

use App\Models\PaymentIntent;
use App\Services\Payments\WebhookResult;
use Illuminate\Http\Request;

/**
 * Checkout-session gateway (PayFast / Paystack / Ozow / Mock). The card or
 * bank details are only ever entered on the gateway's own widget (SAQ-A):
 * createCheckout() returns what the embed page needs to render that widget,
 * and only a verified webhook (verifyWebhook) or a server-to-server status
 * query (fetchStatus) can mark the intent paid.
 *
 * The older synchronous PaymentGatewayInterface (charge/refund) is still
 * honoured for existing callers via App\Services\Payments\OnlinePaymentGateway.
 */
interface CheckoutGatewayInterface
{
    public function key(): string;

    public function label(): string;

    /** Enabled in settings AND all required credentials present. */
    public function isConfigured(): bool;

    public function supportsSubscriptions(): bool;

    /**
     * Prepare a checkout for the intent (may call the gateway API).
     *
     * @return array{mode: string, data: array<string, mixed>}
     *                                                        mode: payfast_onsite | form_post | paystack_inline | mock
     */
    public function createCheckout(PaymentIntent $intent): array;

    /** Verify + parse an incoming webhook/ITN/notify. Never throws for bad input. */
    public function verifyWebhook(Request $request): WebhookResult;

    /**
     * Server-to-server status lookup (trusted). Null when unsupported/unknown.
     *
     * @return array{status: string, gateway_ref?: string|null, amount?: float|null}|null
     */
    public function fetchStatus(PaymentIntent $intent): ?array;

    /** @return array{success: bool, message: string, reference?: string|null} */
    public function refund(PaymentIntent $intent, float $amount, string $reason = 'Refund'): array;
}
