<?php

namespace App\Services\Payments;

use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates online payments:
 *   start()          → PaymentIntent (server-computed amount, idempotency key) + short-lived embed token
 *   checkout()       → what the embed page renders (gateway widget)
 *   handleWebhook()  → verify → log PaymentEvent → apply() idempotently
 *   refresh()        → server-to-server status query fallback (never the client return)
 *   refund()         → gateway refund + payable side effects
 */
class PaymentService
{
    public function __construct(
        protected GatewayManager $gateways,
        protected Payables $payables,
    ) {}

    public function gateways(): GatewayManager
    {
        return $this->gateways;
    }

    public function payables(): Payables
    {
        return $this->payables;
    }

    /**
     * @return array{0: PaymentIntent, 1: string} intent + plain embed token
     */
    public function start(PayableIntent $p): array
    {
        $available = $this->gateways->availableFor($p->service, $p->channel);
        if ($available === []) {
            throw new RuntimeException('Online payments are not available right now. Please try again later.');
        }
        $gateway = null;
        foreach ($available as $g) {
            if ($p->gateway === null || $p->gateway === $g->key()) {
                $gateway = $g;
                break;
            }
        }
        if (! $gateway) {
            throw new RuntimeException('That payment method is not available for this item.');
        }

        $token = Str::random(48);
        $ttl = max(5, (int) PaymentSettings::str('payments_intent_ttl_minutes') ?: 30);

        $intent = DB::transaction(function () use ($p, $gateway, $token, $ttl) {
            if ($p->idempotencyKey) {
                $existing = PaymentIntent::query()->where('idempotency_key', $p->idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    if ((int) $existing->user_id !== (int) $p->user->id) {
                        throw new RuntimeException('Duplicate request.');
                    }
                    if (! $existing->isFinal()) {
                        $existing->update(['embed_token_hash' => hash('sha256', $token), 'embed_expires_at' => now()->addMinutes($ttl)]);
                    }

                    return $existing;
                }
            }

            // Lock the payable row so two tabs can't race different amounts.
            $payable = $p->payable->newQuery()->lockForUpdate()->find($p->payable->getKey());
            $this->payables->assertPayable($payable);

            return PaymentIntent::query()->create([
                'user_id' => $p->user->id,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'amount' => $this->payables->amountFor($payable),
                'currency' => 'ZAR',
                'gateway' => $gateway->key(),
                'channel' => $p->channel === 'app' ? 'app' : 'web',
                'status' => PaymentIntent::STATUS_PENDING,
                'description' => mb_substr($p->description, 0, 191),
                'idempotency_key' => $p->idempotencyKey,
                'embed_token_hash' => hash('sha256', $token),
                'embed_expires_at' => now()->addMinutes($ttl),
                'metadata' => $p->metadata ?: null,
            ]);
        });

        return [$intent, $token];
    }

    public function embedUrl(PaymentIntent $intent, string $token): string
    {
        return route('payments.embed', ['intent' => $intent->uuid]).'?t='.urlencode($token);
    }

    public function validEmbedToken(PaymentIntent $intent, ?string $token): bool
    {
        return $token && $intent->embed_token_hash
            && hash_equals($intent->embed_token_hash, hash('sha256', $token))
            && $intent->embed_expires_at && $intent->embed_expires_at->isFuture();
    }

    /** @return array{mode: string, data: array<string, mixed>} */
    public function checkout(PaymentIntent $intent): array
    {
        $cached = $intent->meta('checkout');
        if (is_array($cached) && isset($cached['mode'])) {
            return $cached;
        }
        $driver = $this->gateways->get($intent->gateway);
        if (! $driver || ($intent->gateway !== 'mock' && ! $driver->isConfigured())) {
            throw new RuntimeException('This payment method is no longer available.');
        }
        $checkout = $driver->createCheckout($intent);
        $intent->update(['metadata' => array_merge($intent->metadata ?? [], ['checkout' => $checkout])]);

        return $checkout;
    }

    /** Public shape used by the web sheet + app. */
    public function present(PaymentIntent $intent): array
    {
        return [
            'id' => $intent->uuid,
            'status' => $intent->status,
            'amount' => (float) $intent->amount,
            'currency' => $intent->currency,
            'gateway' => $intent->gateway,
            'reference' => $intent->reference,
            'description' => $intent->description,
            'payable_type' => $intent->payableAlias(),
            'payable_id' => $intent->payable_id,
            'paid_at' => $intent->paid_at?->toIso8601String(),
            'message' => match ($intent->status) {
                PaymentIntent::STATUS_PAID => 'Payment received. Thank you!',
                PaymentIntent::STATUS_FAILED => $intent->failure_reason ?: 'The payment did not go through.',
                PaymentIntent::STATUS_CANCELLED => 'The payment was cancelled.',
                PaymentIntent::STATUS_PROCESSING => 'Confirming your payment with the bank…',
                PaymentIntent::STATUS_REFUNDED => 'This payment was refunded.',
                default => 'Waiting for payment…',
            },
        ];
    }

    // ------------------------------------------------------------------
    // Webhooks
    // ------------------------------------------------------------------

    /** @return array{ok: bool, outcome: string} */
    public function handleWebhook(string $gatewayKey, Request $request): array
    {
        $driver = in_array($gatewayKey, PaymentSettings::GATEWAYS, true) ? $this->gateways->get($gatewayKey) : null;
        if (! $driver) {
            return ['ok' => false, 'outcome' => 'unknown_gateway'];
        }

        $result = $driver->verifyWebhook($request);
        $intent = $result->reference
            ? PaymentIntent::query()->where('reference', $result->reference)->where('gateway', $gatewayKey)->first()
            : null;

        $event = PaymentEvent::query()->create([
            'payment_intent_id' => $intent?->id,
            'gateway' => $gatewayKey,
            'event_type' => mb_substr($result->eventType, 0, 60),
            'reference' => $result->reference ? mb_substr($result->reference, 0, 120) : null,
            'verified' => $result->verified,
            'verification_note' => mb_substr($result->note, 0, 255),
            'ip' => $request->ip(),
            'payload' => $this->redact($result->payload ?: $request->all()),
            'headers' => array_intersect_key(
                array_map(fn ($v) => is_array($v) ? implode(',', $v) : $v, $request->headers->all()),
                array_flip(['user-agent', 'content-type', 'x-paystack-signature', 'x-forwarded-for'])
            ),
        ]);

        if (! $result->verified) {
            $event->update(['outcome' => 'rejected']);
            Log::warning("[Payments] {$gatewayKey} webhook rejected: {$result->note}", ['ref' => $result->reference]);

            return ['ok' => false, 'outcome' => 'rejected'];
        }
        if (! $intent) {
            $event->update(['outcome' => 'unknown_reference']);

            return ['ok' => true, 'outcome' => 'unknown_reference'];
        }

        $outcome = $this->apply($intent, $result->status, $result->gatewayRef, $result->amount, $result->token, $result->payload);
        $event->update(['outcome' => $outcome]);

        return ['ok' => true, 'outcome' => $outcome];
    }

    /**
     * Apply a TRUSTED status (verified webhook / server status query / mock)
     * to an intent and its payable. Idempotent; returns an outcome label.
     */
    public function apply(PaymentIntent $intent, string $status, ?string $gatewayRef = null, ?float $amount = null, ?string $token = null, array $payload = []): string
    {
        return DB::transaction(function () use ($intent, $status, $gatewayRef, $amount, $token, $payload) {
            /** @var PaymentIntent $intent */
            $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $payable = $intent->payable;

            if ($status === 'paid') {
                // PayFast subscription renewal: same m_payment_id + token, new pf_payment_id.
                if ($intent->status === PaymentIntent::STATUS_PAID) {
                    if ($payable instanceof Subscription && $gatewayRef && $gatewayRef !== $intent->gateway_ref) {
                        return app(SubscriptionService::class)->recordRenewal($payable, $amount ?? (float) $payable->amount, $gatewayRef, $intent->gateway);
                    }

                    return 'already_paid';
                }
                if ($intent->status === PaymentIntent::STATUS_REFUNDED) {
                    return 'already_refunded';
                }
                if ($amount !== null && abs($amount - (float) $intent->amount) > 0.01) {
                    $intent->update([
                        'status' => PaymentIntent::STATUS_PROCESSING,
                        'failure_reason' => 'Amount mismatch: gateway reported R'.number_format($amount, 2).' — needs review.',
                    ]);
                    Log::error('[Payments] amount mismatch', ['intent' => $intent->uuid, 'expected' => $intent->amount, 'got' => $amount]);

                    return 'amount_mismatch';
                }

                $intent->update([
                    'status' => PaymentIntent::STATUS_PAID,
                    'gateway_ref' => $gatewayRef ? mb_substr($gatewayRef, 0, 120) : $intent->gateway_ref,
                    'gateway_token' => $token ?: $intent->gateway_token,
                    'paid_at' => now(),
                    'failure_reason' => null,
                ]);

                if ($payable) {
                    if ($payable instanceof Subscription) {
                        app(SubscriptionService::class)->activate($payable, $intent, $token);
                    } else {
                        $this->payables->markPaid($payable, $intent);
                    }
                }
                if ($token && $intent->meta('save_card') && ! ($payable instanceof Subscription) && $intent->user) {
                    app(SubscriptionService::class)->storeCardToken($intent->user, $intent->gateway, $token);
                }

                return 'paid';
            }

            if ($status === 'refunded') {
                if ($intent->status !== PaymentIntent::STATUS_REFUNDED) {
                    $intent->update(['status' => PaymentIntent::STATUS_REFUNDED, 'refunded_amount' => $intent->amount, 'refunded_at' => now()]);
                    if ($payable) {
                        $this->payables->markRefunded($payable, $intent, (float) $intent->amount);
                    }
                }

                return 'refunded';
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                // PayFast sends CANCELLED for a subscription the buyer/merchant cancelled.
                if ($intent->status === PaymentIntent::STATUS_PAID) {
                    if ($payable instanceof Subscription && $status === 'cancelled') {
                        app(SubscriptionService::class)->markCancelledByGateway($payable);

                        return 'subscription_cancelled';
                    }

                    return 'ignored_after_paid';
                }
                $intent->update([
                    'status' => $status === 'failed' ? PaymentIntent::STATUS_FAILED : PaymentIntent::STATUS_CANCELLED,
                    'failure_reason' => $status === 'failed' ? (string) (data_get($payload, 'StatusMessage') ?: 'Declined by the bank or card issuer.') : null,
                ]);

                return $status;
            }

            if (in_array($status, ['pending', 'processing'], true) && $intent->status === PaymentIntent::STATUS_PENDING) {
                $intent->update(['status' => PaymentIntent::STATUS_PROCESSING]);

                return 'processing';
            }

            return 'ignored';
        });
    }

    /** Test-mode completion; refused unless the mock gateway is the active one. */
    public function completeMock(PaymentIntent $intent, bool $success = true): string
    {
        if ($intent->gateway !== 'mock' || ! $this->gateways->mockActive()) {
            throw new RuntimeException('Test payments are switched off.');
        }
        PaymentEvent::query()->create([
            'payment_intent_id' => $intent->id, 'gateway' => 'mock', 'event_type' => 'mock_'.($success ? 'paid' : 'cancelled'),
            'reference' => $intent->reference, 'verified' => true, 'verification_note' => 'Test mode (no gateway configured)',
            'outcome' => $success ? 'paid' : 'cancelled', 'payload' => ['amount' => $intent->amount],
        ]);

        return $this->apply($intent, $success ? 'paid' : 'cancelled', 'MOCK-'.strtoupper(Str::random(10)), (float) $intent->amount, $intent->meta('save_card') ? 'MOCKTOKEN-'.Str::random(12) : null);
    }

    /** Server-to-server status check (throttled per intent). */
    public function refresh(PaymentIntent $intent, int $minSeconds = 15): PaymentIntent
    {
        if (! in_array($intent->status, [PaymentIntent::STATUS_PENDING, PaymentIntent::STATUS_PROCESSING], true)
            || $intent->gateway === 'mock'
            || ($intent->last_checked_at && $intent->last_checked_at->diffInSeconds(now()) < $minSeconds)
            || $intent->created_at->diffInSeconds(now()) < 20) {
            return $intent;
        }
        $intent->forceFill(['last_checked_at' => now()])->save();
        $driver = $this->gateways->get($intent->gateway);
        $status = $driver?->fetchStatus($intent);
        if ($status && in_array($status['status'], ['paid', 'failed', 'cancelled', 'refunded'], true)) {
            PaymentEvent::query()->create([
                'payment_intent_id' => $intent->id, 'gateway' => $intent->gateway, 'event_type' => 'status_query',
                'reference' => $intent->reference, 'verified' => true, 'verification_note' => 'Server-to-server status query',
                'payload' => $status,
            ])->update(['outcome' => $this->apply($intent, $status['status'], $status['gateway_ref'] ?? null, $status['amount'] ?? null)]);
        }

        return $intent->fresh();
    }

    public function cancelByUser(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->status === PaymentIntent::STATUS_PENDING) {
            $intent->update(['status' => PaymentIntent::STATUS_CANCELLED]);
        }

        return $intent->fresh();
    }

    /** @return array{success: bool, message: string} */
    public function refund(PaymentIntent $intent, ?float $amount = null, string $reason = 'Refund', bool $manual = false): array
    {
        if ($intent->status !== PaymentIntent::STATUS_PAID) {
            return ['success' => false, 'message' => 'Only paid payments can be refunded.'];
        }
        $remaining = round((float) $intent->amount - (float) $intent->refunded_amount, 2);
        $amount = round(min($amount ?? $remaining, $remaining), 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Nothing left to refund.'];
        }

        if (! $manual) {
            $driver = $this->gateways->get($intent->gateway);
            $result = $driver ? $driver->refund($intent, $amount, $reason) : ['success' => false, 'message' => 'Gateway unavailable.'];
            if (empty($result['success'])) {
                return ['success' => false, 'message' => $result['message'] ?? 'Refund failed.'];
            }
        }

        $refunded = round((float) $intent->refunded_amount + $amount, 2);
        $full = $refunded + 0.009 >= (float) $intent->amount;
        $intent->update([
            'refunded_amount' => $refunded,
            'refunded_at' => now(),
            'status' => $full ? PaymentIntent::STATUS_REFUNDED : PaymentIntent::STATUS_PAID,
            'metadata' => array_merge($intent->metadata ?? [], ['refunds' => array_merge((array) $intent->meta('refunds', []), [[
                'amount' => $amount, 'reason' => $reason, 'manual' => $manual, 'at' => now()->toIso8601String(),
            ]])]),
        ]);
        if ($intent->payable) {
            $this->payables->markRefunded($intent->payable, $intent, $refunded);
        }

        return ['success' => true, 'message' => ($manual ? 'Marked as refunded: R' : 'Refunded R').number_format($amount, 2).'.'];
    }

    /** Cron-optional sweep: re-check stale pending intents, expire very old ones. */
    public function reconcileStale(): int
    {
        $n = 0;
        PaymentIntent::query()
            ->whereIn('status', [PaymentIntent::STATUS_PENDING, PaymentIntent::STATUS_PROCESSING])
            ->where('gateway', '!=', 'mock')
            ->where('created_at', '<', now()->subMinutes(2))
            ->where('created_at', '>', now()->subDays(2))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subMinutes(10)))
            ->limit(20)->get()
            ->each(function (PaymentIntent $i) use (&$n) {
                try {
                    $this->refresh($i, 0);
                    $n++;
                } catch (\Throwable $e) {
                    Log::info('[Payments] reconcile failed: '.$e->getMessage());
                }
            });

        PaymentIntent::query()->where('status', PaymentIntent::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays(2))
            ->limit(200)->update(['status' => PaymentIntent::STATUS_CANCELLED, 'failure_reason' => 'Expired']);

        return $n;
    }

    public function userCanSee(PaymentIntent $intent, ?User $user): bool
    {
        return $user && (int) $intent->user_id === (int) $user->id;
    }

    /** Drop anything that looks like card data / secrets before logging. */
    protected function redact(array $payload): array
    {
        array_walk_recursive($payload, function (&$v, $k) {
            if (is_string($k) && preg_match('/(card|cvv|pan|passphrase|secret|signature_key|authorization_code)/i', $k)) {
                $v = '[redacted]';
            }
        });

        return $payload;
    }
}
