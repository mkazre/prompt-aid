<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\CheckoutGatewayInterface;
use App\Models\PaymentIntent;
use App\Services\Payments\WebhookResult;
use Illuminate\Http\Request;

/**
 * Test-mode checkout used while no real gateway is enabled + configured.
 * The embed page shows a clearly labelled "test payment" card; confirming
 * it calls PaymentService::completeMock (refused once any real gateway is live).
 */
class MockGateway implements CheckoutGatewayInterface
{
    public function key(): string
    {
        return 'mock';
    }

    public function label(): string
    {
        return 'Test payment';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsSubscriptions(): bool
    {
        return true;
    }

    public function createCheckout(PaymentIntent $intent): array
    {
        return ['mode' => 'mock', 'data' => []];
    }

    public function verifyWebhook(Request $request): WebhookResult
    {
        return WebhookResult::rejected('The mock gateway has no webhooks');
    }

    public function fetchStatus(PaymentIntent $intent): ?array
    {
        return null;
    }

    public function refund(PaymentIntent $intent, float $amount, string $reason = 'Refund'): array
    {
        return ['success' => true, 'message' => 'Refund of R'.number_format($amount, 2).' simulated (test mode).', 'reference' => 'MOCKREF-'.$intent->id];
    }
}
