<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\CheckoutGatewayInterface;
use App\Models\PaymentIntent;
use App\Services\Payments\WebhookResult;
use App\Support\PaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack (ZAR). Server initialises the transaction (amount from our DB),
 * the embed page resumes it with Paystack Inline JS v2 (popup inside the
 * page / in-app WebView). Webhooks are verified with HMAC-SHA512 of the raw
 * body using the secret key (`x-paystack-signature`).
 */
class PaystackGateway implements CheckoutGatewayInterface
{
    public const API = 'https://api.paystack.co';

    public const WEBHOOK_IPS = ['52.31.139.75', '52.49.173.169', '52.214.14.220'];

    public function key(): string
    {
        return 'paystack';
    }

    public function label(): string
    {
        return 'Paystack';
    }

    public function isConfigured(): bool
    {
        return PaymentSettings::bool('paystack_enabled')
            && PaymentSettings::str('paystack_public_key') !== ''
            && PaymentSettings::hasSecret('paystack_secret_key');
    }

    public function supportsSubscriptions(): bool
    {
        return false;
    }

    public function testMode(): bool
    {
        return str_starts_with(PaymentSettings::str('paystack_public_key'), 'pk_test_');
    }

    public static function webhookSignature(string $rawBody, string $secret): string
    {
        return hash_hmac('sha512', $rawBody, $secret);
    }

    protected function http()
    {
        return Http::withToken(PaymentSettings::secret('paystack_secret_key'))->acceptJson()->timeout(25);
    }

    public function createCheckout(PaymentIntent $intent): array
    {
        $email = (string) ($intent->user?->email ?: 'patient@promptaid.health');
        $res = $this->http()->post(self::API.'/transaction/initialize', [
            'email' => $email,
            'amount' => (int) round($intent->amount * 100),
            'currency' => 'ZAR',
            'reference' => $intent->reference,
            'callback_url' => route('payments.return', ['intent' => $intent->uuid, 'outcome' => 'success']),
            'metadata' => [
                'intent' => $intent->uuid,
                'cancel_action' => route('payments.return', ['intent' => $intent->uuid, 'outcome' => 'cancel']),
                'custom_fields' => [['display_name' => 'For', 'variable_name' => 'for', 'value' => (string) $intent->description]],
            ],
        ]);

        $access = $res->json('data.access_code');
        if (! $res->successful() || ! $res->json('status') || ! $access) {
            Log::warning('[Paystack] initialize failed', ['status' => $res->status(), 'message' => $res->json('message')]);
            throw new \RuntimeException('Paystack could not start the payment: '.($res->json('message') ?: 'please try another method.'));
        }

        return ['mode' => 'paystack_inline', 'data' => [
            'access_code' => $access,
            'public_key' => PaymentSettings::str('paystack_public_key'),
            'email' => $email,
            'amount' => (int) round($intent->amount * 100),
            'reference' => $intent->reference,
            'authorization_url' => $res->json('data.authorization_url'),
        ]];
    }

    public function verifyWebhook(Request $request): WebhookResult
    {
        $raw = (string) $request->getContent();
        $payload = json_decode($raw, true) ?: [];
        $data = (array) ($payload['data'] ?? []);
        $reference = isset($data['reference']) ? (string) $data['reference'] : (isset($data['transaction_reference']) ? (string) $data['transaction_reference'] : null);

        $secret = PaymentSettings::secret('paystack_secret_key');
        $sig = (string) $request->header('x-paystack-signature', '');
        if ($secret === '' || $sig === '' || ! hash_equals(self::webhookSignature($raw, $secret), $sig)) {
            return WebhookResult::rejected('Invalid x-paystack-signature', $payload, $reference);
        }
        if (PaymentSettings::bool('paystack_validate_ip') && ! in_array((string) $request->ip(), self::WEBHOOK_IPS, true)) {
            return WebhookResult::rejected('Source IP '.$request->ip().' is not a Paystack webhook IP', $payload, $reference);
        }

        $event = (string) ($payload['event'] ?? '');
        if (isset($data['currency']) && strtoupper((string) $data['currency']) !== 'ZAR') {
            return new WebhookResult(true, 'Non-ZAR currency ignored', $reference, 'ignored', eventType: $event, payload: $payload);
        }

        $status = match ($event) {
            'charge.success' => 'paid',
            'refund.processed' => 'refunded',
            default => 'ignored',
        };
        $auth = (array) ($data['authorization'] ?? []);

        return new WebhookResult(
            verified: true,
            note: 'HMAC OK',
            reference: $reference,
            status: $status,
            gatewayRef: isset($data['id']) ? (string) $data['id'] : null,
            amount: isset($data['amount']) ? ((int) $data['amount']) / 100 : null,
            token: ! empty($auth['reusable']) && ! empty($auth['authorization_code']) ? (string) $auth['authorization_code'] : null,
            eventType: $event ?: 'unknown',
            payload: $payload,
        );
    }

    public function fetchStatus(PaymentIntent $intent): ?array
    {
        try {
            $res = $this->http()->get(self::API.'/transaction/verify/'.rawurlencode($intent->reference));
            if (! $res->successful() || ! $res->json('status')) {
                return null;
            }
            $mapped = match ((string) $res->json('data.status')) {
                'success' => 'paid',
                'failed' => 'failed',
                'reversed' => 'refunded',
                default => null,
            };
            if (! $mapped || strtoupper((string) $res->json('data.currency')) !== 'ZAR') {
                return null;
            }

            return ['status' => $mapped, 'gateway_ref' => (string) $res->json('data.id'), 'amount' => ((int) $res->json('data.amount')) / 100];
        } catch (\Throwable $e) {
            Log::info('[Paystack] verify failed: '.$e->getMessage());

            return null;
        }
    }

    public function refund(PaymentIntent $intent, float $amount, string $reason = 'Refund'): array
    {
        try {
            $res = $this->http()->post(self::API.'/refund', [
                'transaction' => $intent->reference,
                'amount' => (int) round($amount * 100),
                'merchant_note' => mb_substr($reason, 0, 120),
            ]);
            $ok = $res->successful() && $res->json('status');

            return ['success' => (bool) $ok, 'message' => $ok ? 'Refund submitted to Paystack.' : 'Paystack refund failed: '.($res->json('message') ?: $res->status()), 'reference' => (string) $res->json('data.id')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Paystack refund error: '.$e->getMessage()];
        }
    }

    /** Charge a saved reusable authorization (card on file). Webhook confirms. */
    public function chargeAuthorization(PaymentIntent $intent, string $authorizationCode): array
    {
        try {
            $res = $this->http()->post(self::API.'/transaction/charge_authorization', [
                'authorization_code' => $authorizationCode,
                'email' => (string) ($intent->user?->email ?? ''),
                'amount' => (int) round($intent->amount * 100),
                'currency' => 'ZAR',
                'reference' => $intent->reference,
            ]);
            $ok = $res->successful() && $res->json('status') && $res->json('data.status') === 'success';

            return ['success' => (bool) $ok, 'message' => $ok ? 'Charged.' : 'Paystack: '.($res->json('message') ?: 'declined'), 'reference' => (string) $res->json('data.id')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Paystack error: '.$e->getMessage()];
        }
    }
}
