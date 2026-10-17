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
 * Ozow instant EFT. Redirect-based by nature, so the signed form is posted
 * INSIDE our own frame (web modal iframe / in-app WebView sheet) — the
 * success/cancel/error URLs are intercepted there, never opened in an
 * external browser. Notify calls are verified with the response hash and,
 * when an API key is configured, the GetTransactionByReference status API.
 */
class OzowGateway implements CheckoutGatewayInterface
{
    public const PAY_URL = 'https://pay.ozow.com';

    public const API = 'https://api.ozow.com';

    public const REQUEST_HASH_ORDER = [
        'SiteCode', 'CountryCode', 'CurrencyCode', 'Amount', 'TransactionReference', 'BankReference',
        'Optional1', 'Optional2', 'Optional3', 'Optional4', 'Optional5', 'Customer',
        'CancelUrl', 'ErrorUrl', 'SuccessUrl', 'NotifyUrl', 'IsTest',
    ];

    public const RESPONSE_HASH_ORDER = [
        'SiteCode', 'TransactionId', 'TransactionReference', 'Amount', 'Status',
        'Optional1', 'Optional2', 'Optional3', 'Optional4', 'Optional5',
        'CurrencyCode', 'IsTest', 'StatusMessage',
    ];

    public function key(): string
    {
        return 'ozow';
    }

    public function label(): string
    {
        return 'Ozow';
    }

    public function isConfigured(): bool
    {
        return PaymentSettings::bool('ozow_enabled')
            && PaymentSettings::str('ozow_site_code') !== ''
            && PaymentSettings::hasSecret('ozow_private_key');
    }

    public function supportsSubscriptions(): bool
    {
        return false;
    }

    public function isTest(): bool
    {
        return PaymentSettings::bool('ozow_sandbox');
    }

    /** SHA512 of the lowercased concatenation of values in $order + private key. */
    public static function hash(array $fields, array $order, string $privateKey): string
    {
        $concat = '';
        foreach ($order as $key) {
            $concat .= (string) ($fields[$key] ?? '');
        }

        return hash('sha512', strtolower($concat.$privateKey));
    }

    /** @return array<string, string> */
    public function requestFields(PaymentIntent $intent): array
    {
        $fields = [
            'SiteCode' => PaymentSettings::str('ozow_site_code'),
            'CountryCode' => 'ZA',
            'CurrencyCode' => 'ZAR',
            'Amount' => number_format((float) $intent->amount, 2, '.', ''),
            'TransactionReference' => $intent->reference,
            'BankReference' => mb_substr('PromptAid '.substr($intent->reference, -8), 0, 20),
            'Optional1' => $intent->uuid,
            'Customer' => mb_substr((string) ($intent->user?->email ?? ''), 0, 100),
            'CancelUrl' => route('payments.return', ['intent' => $intent->uuid, 'outcome' => 'cancel']),
            'ErrorUrl' => route('payments.return', ['intent' => $intent->uuid, 'outcome' => 'error']),
            'SuccessUrl' => route('payments.return', ['intent' => $intent->uuid, 'outcome' => 'success']),
            'NotifyUrl' => url('/api/payments/webhooks/ozow'),
            'IsTest' => $this->isTest() ? 'true' : 'false',
        ];
        $fields['HashCheck'] = self::hash($fields, self::REQUEST_HASH_ORDER, PaymentSettings::secret('ozow_private_key'));

        return $fields;
    }

    public function createCheckout(PaymentIntent $intent): array
    {
        return ['mode' => 'form_post', 'data' => [
            'action' => self::PAY_URL,
            'fields' => $this->requestFields($intent),
        ]];
    }

    public function verifyWebhook(Request $request): WebhookResult
    {
        $posted = [];
        parse_str((string) $request->getContent(), $posted);
        if (! $posted) {
            $posted = $request->all();
        }
        $reference = isset($posted['TransactionReference']) ? (string) $posted['TransactionReference'] : null;

        $given = strtolower((string) ($posted['Hash'] ?? $posted['HashCheck'] ?? ''));
        $expected = self::hash($posted, self::RESPONSE_HASH_ORDER, PaymentSettings::secret('ozow_private_key'));
        if ($given === '' || ! hash_equals($expected, $given)) {
            return WebhookResult::rejected('Response hash mismatch', $posted, $reference);
        }
        if ((string) ($posted['SiteCode'] ?? '') !== PaymentSettings::str('ozow_site_code')) {
            return WebhookResult::rejected('Site code mismatch', $posted, $reference);
        }
        if (! $this->isTest() && strtolower((string) ($posted['IsTest'] ?? 'false')) === 'true') {
            return WebhookResult::rejected('Test notification received while live', $posted, $reference);
        }

        $status = $this->mapStatus((string) ($posted['Status'] ?? ''));
        $note = 'Hash OK';

        // Belt and braces: confirm a "Complete" with Ozow's status API when we can.
        if ($status === 'paid' && PaymentSettings::hasSecret('ozow_api_key') && $reference) {
            $api = $this->lookup($reference);
            if ($api === null) {
                $note .= '; status API unavailable';
            } elseif ($api['status'] !== 'paid') {
                return WebhookResult::rejected('Status API says '.$api['status'], $posted, $reference);
            } else {
                $note .= '; status API confirmed';
            }
        }

        return new WebhookResult(
            verified: true,
            note: $note,
            reference: $reference,
            status: $status,
            gatewayRef: isset($posted['TransactionId']) ? (string) $posted['TransactionId'] : null,
            amount: isset($posted['Amount']) ? (float) $posted['Amount'] : null,
            eventType: 'notify',
            payload: $posted,
        );
    }

    protected function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'complete' => 'paid',
            'cancelled', 'abandoned' => 'cancelled',
            'error' => 'failed',
            'pendinginvestigation' => 'processing',
            'pending' => 'pending',
            default => 'ignored',
        };
    }

    /** @return array{status: string, gateway_ref: ?string, amount: ?float}|null */
    protected function lookup(string $reference): ?array
    {
        try {
            $res = Http::withHeaders(['ApiKey' => PaymentSettings::secret('ozow_api_key')])->acceptJson()->timeout(20)
                ->get(self::API.'/GetTransactionByReference', [
                    'siteCode' => PaymentSettings::str('ozow_site_code'),
                    'transactionReference' => $reference,
                    'isTest' => $this->isTest() ? 'true' : 'false',
                ]);
            if (! $res->successful()) {
                return null;
            }
            $rows = $res->json();
            $rows = isset($rows['transactionId']) ? [$rows] : (array) $rows;
            $best = null;
            foreach ($rows as $row) {
                $mapped = $this->mapStatus((string) ($row['status'] ?? ''));
                if ($mapped === 'paid') {
                    $best = $row;
                    break;
                }
                $best ??= $row;
            }
            if (! $best) {
                return null;
            }

            return [
                'status' => $this->mapStatus((string) ($best['status'] ?? '')),
                'gateway_ref' => isset($best['transactionId']) ? (string) $best['transactionId'] : null,
                'amount' => isset($best['amount']) ? (float) $best['amount'] : null,
            ];
        } catch (\Throwable $e) {
            Log::info('[Ozow] status lookup failed: '.$e->getMessage());

            return null;
        }
    }

    public function fetchStatus(PaymentIntent $intent): ?array
    {
        if (! PaymentSettings::hasSecret('ozow_api_key')) {
            return null;
        }
        $r = $this->lookup($intent->reference);

        return $r && in_array($r['status'], ['paid', 'failed', 'cancelled'], true) ? $r : null;
    }

    public function refund(PaymentIntent $intent, float $amount, string $reason = 'Refund'): array
    {
        return [
            'success' => false,
            'message' => 'Ozow refunds are processed from the Ozow merchant dashboard (transaction '.($intent->gateway_ref ?: $intent->reference).'). Use "Mark refunded" here once done.',
        ];
    }
}
