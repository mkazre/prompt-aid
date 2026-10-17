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
 * PayFast (South Africa).
 *
 *  - Checkout: Onsite Payments — we POST the signed fields server-side to
 *    /onsite/process, get a uuid, and the embed page opens PayFast's own
 *    modal via engine.js (card data never touches our server). With
 *    "onsite" switched off, the signed form is auto-posted to /eng/process
 *    INSIDE the in-app sheet / web modal frame instead.
 *  - ITN: signature + (optional) source host/IP + amount + server-side
 *    /eng/query/validate, all mandatory before anything is marked paid.
 *  - Subscriptions: subscription_type=1 (recurring) and =2 (tokenisation for
 *    ad-hoc charges) + the api.payfast.co.za subscription endpoints.
 */
class PayFastGateway implements CheckoutGatewayInterface
{
    /** Field order from the PayFast "custom integration" docs — signature depends on it. */
    public const FIELD_ORDER = [
        'merchant_id', 'merchant_key', 'return_url', 'cancel_url', 'notify_url',
        'name_first', 'name_last', 'email_address', 'cell_number',
        'm_payment_id', 'amount', 'item_name', 'item_description',
        'custom_int1', 'custom_int2', 'custom_int3', 'custom_int4', 'custom_int5',
        'custom_str1', 'custom_str2', 'custom_str3', 'custom_str4', 'custom_str5',
        'email_confirmation', 'confirmation_address', 'payment_method',
        'subscription_type', 'billing_date', 'recurring_amount', 'frequency', 'cycles',
        'subscription_notify_email', 'subscription_notify_webhook', 'subscription_notify_buyer',
    ];

    public const VALID_HOSTS = ['www.payfast.co.za', 'sandbox.payfast.co.za', 'w1w.payfast.co.za', 'w2w.payfast.co.za'];

    public function key(): string
    {
        return 'payfast';
    }

    public function label(): string
    {
        return 'PayFast';
    }

    public function isConfigured(): bool
    {
        return PaymentSettings::bool('payfast_enabled')
            && PaymentSettings::str('payfast_merchant_id') !== ''
            && PaymentSettings::hasSecret('payfast_merchant_key');
    }

    public function supportsSubscriptions(): bool
    {
        return PaymentSettings::bool('payfast_subscriptions_enabled');
    }

    public function sandbox(): bool
    {
        return PaymentSettings::bool('payfast_sandbox');
    }

    public function host(): string
    {
        return $this->sandbox() ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
    }

    public function engineJsUrl(): string
    {
        return 'https://'.$this->host().'/onsite/engine.js';
    }

    protected function passphrase(): ?string
    {
        $p = PaymentSettings::secret('payfast_passphrase');

        return $p === '' ? null : $p;
    }

    // ------------------------------------------------------------------
    // Signatures (static + pure so they can be checked against the docs)
    // ------------------------------------------------------------------

    /**
     * Checkout signature: fields in documented order, blanks skipped,
     * key=urlencode(trim(value)) joined by &, then &passphrase=..., MD5.
     */
    public static function signature(array $data, ?string $passphrase = null): string
    {
        $parts = [];
        foreach ($data as $key => $val) {
            if ($key === 'signature' || $val === null || $val === '') {
                continue;
            }
            $parts[] = $key.'='.urlencode(trim((string) $val));
        }
        $string = implode('&', $parts);
        if ($passphrase !== null && $passphrase !== '') {
            $string .= '&passphrase='.urlencode(trim($passphrase));
        }

        return md5($string);
    }

    /** ITN: every posted field in posted order up to `signature` (blanks included). */
    public static function itnParamString(array $posted): string
    {
        $parts = [];
        foreach ($posted as $key => $val) {
            if ($key === 'signature') {
                break;
            }
            $parts[] = $key.'='.urlencode((string) $val);
        }

        return implode('&', $parts);
    }

    public static function itnSignature(array $posted, ?string $passphrase = null): string
    {
        $string = self::itnParamString($posted);
        if ($passphrase !== null && $passphrase !== '') {
            $string .= '&passphrase='.urlencode(trim($passphrase));
        }

        return md5($string);
    }

    /** REST API signature: headers + body (+ passphrase) sorted by key, urlencoded, MD5. */
    public static function apiSignature(array $headersAndBody, ?string $passphrase = null): string
    {
        if ($passphrase !== null && $passphrase !== '') {
            $headersAndBody['passphrase'] = $passphrase;
        }
        ksort($headersAndBody);
        $parts = [];
        foreach ($headersAndBody as $key => $val) {
            $parts[] = $key.'='.urlencode((string) $val);
        }

        return md5(implode('&', $parts));
    }

    // ------------------------------------------------------------------
    // Checkout
    // ------------------------------------------------------------------

    /** @return array<string, string> ordered, signed checkout fields */
    public function checkoutFields(PaymentIntent $intent): array
    {
        $user = $intent->user;
        $name = trim((string) ($user?->name ?? ''));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $fields = [
            'merchant_id' => PaymentSettings::str('payfast_merchant_id'),
            'merchant_key' => PaymentSettings::secret('payfast_merchant_key'),
            'return_url' => route('payments.return', ['intent' => $intent->uuid, 'outcome' => 'success']),
            'cancel_url' => route('payments.return', ['intent' => $intent->uuid, 'outcome' => 'cancel']),
            'notify_url' => url('/api/payments/webhooks/payfast'),
            'name_first' => mb_substr($first, 0, 100),
            'name_last' => mb_substr($last, 0, 100),
            'email_address' => (string) ($user?->email ?? ''),
            'm_payment_id' => $intent->reference,
            'amount' => number_format((float) $intent->amount, 2, '.', ''),
            'item_name' => mb_substr($intent->description ?: 'PromptAid payment', 0, 100),
            'custom_str1' => $intent->uuid,
        ];

        $sub = (array) $intent->meta('payfast_subscription', []);
        if (($sub['type'] ?? null) === 1) {
            $fields['subscription_type'] = '1';
            $fields['billing_date'] = (string) ($sub['billing_date'] ?? now()->toDateString());
            $fields['recurring_amount'] = number_format((float) ($sub['recurring_amount'] ?? $intent->amount), 2, '.', '');
            $fields['frequency'] = (string) ($sub['frequency'] ?? 3);
            $fields['cycles'] = (string) ($sub['cycles'] ?? 0);
        } elseif (($sub['type'] ?? null) === 2) {
            $fields['subscription_type'] = '2';
        }

        $ordered = [];
        foreach (self::FIELD_ORDER as $key) {
            if (isset($fields[$key]) && $fields[$key] !== '') {
                $ordered[$key] = $fields[$key];
            }
        }
        $ordered['signature'] = self::signature($ordered, $this->passphrase());

        return $ordered;
    }

    public function createCheckout(PaymentIntent $intent): array
    {
        $fields = $this->checkoutFields($intent);

        if (PaymentSettings::bool('payfast_onsite')) {
            $res = Http::asForm()->timeout(20)->post('https://'.$this->host().'/onsite/process', $fields);
            $uuid = $res->json('uuid');
            if (! $res->successful() || ! is_string($uuid) || $uuid === '') {
                Log::warning('[PayFast] onsite/process failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
                throw new \RuntimeException('PayFast could not start the payment. Please try another method.');
            }

            return ['mode' => 'payfast_onsite', 'data' => [
                'uuid' => $uuid,
                'engine' => $this->engineJsUrl(),
            ]];
        }

        return ['mode' => 'form_post', 'data' => [
            'action' => 'https://'.$this->host().'/eng/process',
            'fields' => $fields,
        ]];
    }

    // ------------------------------------------------------------------
    // ITN
    // ------------------------------------------------------------------

    public function verifyWebhook(Request $request): WebhookResult
    {
        // Raw body keeps PayFast's field order and untrimmed values (the
        // TrimStrings / empty→null middleware would break the signature).
        $posted = [];
        parse_str((string) $request->getContent(), $posted);
        if (! $posted) {
            $posted = $request->post();
        }
        $reference = isset($posted['m_payment_id']) ? (string) $posted['m_payment_id'] : null;

        if (! isset($posted['signature'])) {
            return WebhookResult::rejected('Missing signature', $posted, $reference);
        }
        if (! hash_equals(self::itnSignature($posted, $this->passphrase()), (string) $posted['signature'])) {
            return WebhookResult::rejected('Signature mismatch', $posted, $reference);
        }
        if ((string) ($posted['merchant_id'] ?? '') !== PaymentSettings::str('payfast_merchant_id')) {
            return WebhookResult::rejected('Merchant id mismatch', $posted, $reference);
        }

        if (PaymentSettings::bool('payfast_validate_ip') && ! $this->ipIsPayFast((string) $request->ip())) {
            return WebhookResult::rejected('Source IP '.$request->ip().' is not a PayFast host', $posted, $reference);
        }

        // Server-side confirmation with PayFast.
        try {
            $check = Http::timeout(20)
                ->withBody(self::itnParamString($posted), 'application/x-www-form-urlencoded')
                ->post('https://'.$this->host().'/eng/query/validate');
            if (trim($check->body()) !== 'VALID') {
                return WebhookResult::rejected('PayFast validate said: '.mb_substr(trim($check->body()), 0, 60), $posted, $reference);
            }
        } catch (\Throwable $e) {
            return WebhookResult::rejected('PayFast validate unreachable: '.$e->getMessage(), $posted, $reference);
        }

        $status = match (strtoupper((string) ($posted['payment_status'] ?? ''))) {
            'COMPLETE' => 'paid',
            'CANCELLED' => 'cancelled',
            'FAILED' => 'failed',
            'PENDING' => 'pending',
            default => 'ignored',
        };

        return new WebhookResult(
            verified: true,
            note: 'Signature, host and server validation OK',
            reference: $reference,
            status: $status,
            gatewayRef: isset($posted['pf_payment_id']) ? (string) $posted['pf_payment_id'] : null,
            amount: isset($posted['amount_gross']) ? (float) $posted['amount_gross'] : null,
            token: ! empty($posted['token']) ? (string) $posted['token'] : null,
            eventType: ! empty($posted['token']) ? 'itn_subscription' : 'itn',
            payload: $posted,
        );
    }

    protected function ipIsPayFast(string $ip): bool
    {
        $valid = cache()->remember('payfast_itn_ips', 3600, function () {
            $ips = [];
            foreach (self::VALID_HOSTS as $host) {
                $resolved = @gethostbynamel($host);
                if (is_array($resolved)) {
                    $ips = array_merge($ips, $resolved);
                }
            }

            return array_values(array_unique($ips));
        });

        return in_array($ip, (array) $valid, true);
    }

    // ------------------------------------------------------------------
    // REST API (status, refunds, subscriptions, ad-hoc token charges)
    // ------------------------------------------------------------------

    /** @return \Illuminate\Http\Client\Response */
    public function api(string $method, string $path, array $body = [])
    {
        $headers = [
            'merchant-id' => PaymentSettings::str('payfast_merchant_id'),
            'version' => 'v1',
            'timestamp' => now('Africa/Johannesburg')->format('Y-m-d\TH:i:sP'),
        ];
        $headers['signature'] = self::apiSignature(array_merge($headers, $body), $this->passphrase());

        $url = 'https://api.payfast.co.za/'.ltrim($path, '/').($this->sandbox() ? (str_contains($path, '?') ? '&' : '?').'testing=true' : '');
        $req = Http::withHeaders($headers)->acceptJson()->timeout(25);
        $method = strtolower($method);

        return $method === 'get' ? $req->get($url) : $req->asForm()->{$method}($url, $body);
    }

    public function fetchStatus(PaymentIntent $intent): ?array
    {
        try {
            $res = $this->api('GET', 'process/query/'.urlencode($intent->gateway_ref ?: $intent->reference));
            if (! $res->successful()) {
                return null;
            }
            $status = strtoupper((string) ($res->json('data.response.status') ?? $res->json('data.response.payment_status') ?? ''));
            $mapped = match ($status) {
                'COMPLETE' => 'paid',
                'CANCELLED' => 'cancelled',
                'FAILED' => 'failed',
                default => null,
            };

            return $mapped ? [
                'status' => $mapped,
                'gateway_ref' => $res->json('data.response.pf_payment_id'),
                'amount' => is_numeric($res->json('data.response.amount_gross')) ? (float) $res->json('data.response.amount_gross') : null,
            ] : null;
        } catch (\Throwable $e) {
            Log::info('[PayFast] status query failed: '.$e->getMessage());

            return null;
        }
    }

    public function refund(PaymentIntent $intent, float $amount, string $reason = 'Refund'): array
    {
        if (! $intent->gateway_ref) {
            return ['success' => false, 'message' => 'No PayFast payment id on this intent.'];
        }
        try {
            $res = $this->api('POST', 'refunds/'.urlencode($intent->gateway_ref), [
                'amount' => (int) round($amount * 100),
                'reason' => mb_substr($reason, 0, 100),
                'notify_buyer' => 1,
            ]);
            $ok = $res->successful() && in_array(strtolower((string) ($res->json('status') ?? '')), ['success', 'ok'], true);

            return ['success' => $ok, 'message' => $ok ? 'Refund submitted to PayFast.' : 'PayFast refund failed: '.mb_substr($res->body(), 0, 200), 'reference' => $intent->gateway_ref];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'PayFast refund error: '.$e->getMessage()];
        }
    }

    /** @return array{success: bool, message: string, data?: mixed} */
    public function subscriptionAction(string $token, string $action, array $body = []): array
    {
        $map = ['fetch' => 'GET', 'pause' => 'PUT', 'unpause' => 'PUT', 'cancel' => 'PUT', 'update' => 'PATCH'];
        if (! isset($map[$action])) {
            return ['success' => false, 'message' => 'Unknown action'];
        }
        try {
            $res = $this->api($map[$action], 'subscriptions/'.urlencode($token).'/'.$action, $body);
            $ok = $res->successful() && strtolower((string) ($res->json('status') ?? 'success')) === 'success';

            return ['success' => $ok, 'message' => $ok ? 'OK' : 'PayFast: '.mb_substr($res->body(), 0, 200), 'data' => $res->json('data')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'PayFast error: '.$e->getMessage()];
        }
    }

    /** Ad-hoc charge against a tokenisation agreement (subscription_type=2). The ITN confirms it. */
    public function adhocCharge(string $token, PaymentIntent $intent): array
    {
        try {
            $res = $this->api('POST', 'subscriptions/'.urlencode($token).'/adhoc', [
                'amount' => (int) round($intent->amount * 100),
                'item_name' => mb_substr($intent->description ?: 'PromptAid charge', 0, 100),
                'm_payment_id' => $intent->reference,
            ]);
            $ok = $res->successful() && strtolower((string) ($res->json('status') ?? '')) === 'success';

            return ['success' => $ok, 'message' => $ok ? 'Charge submitted.' : 'PayFast: '.mb_substr($res->body(), 0, 200), 'reference' => $res->json('data.pf_payment_id')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'PayFast error: '.$e->getMessage()];
        }
    }
}
