<?php

namespace App\Services\Payments;

use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recurring billing on PayFast (subscription_type=1) and card-on-file
 * tokens (subscription_type=2 / Paystack reusable authorisations).
 * In test mode (no gateway configured) subscriptions run on the mock.
 */
class SubscriptionService
{
    public function __construct(
        protected PaymentService $payments,
        protected GatewayManager $gateways,
    ) {}

    /**
     * Create a pending subscription + its first-payment intent.
     *
     * @return array{0: Subscription, 1: PaymentIntent, 2: string}
     */
    public function subscribe(User $user, SubscriptionPlan $plan, ?string $gateway = null, string $channel = 'web', ?string $subjectType = null, ?int $subjectId = null, ?string $idempotencyKey = null): array
    {
        if (! $plan->is_active) {
            throw new RuntimeException('This plan is not available.');
        }
        $already = Subscription::query()->where('user_id', $user->id)->where('subscription_plan_id', $plan->id)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAUSED])->exists();
        if ($already) {
            throw new RuntimeException('You already have this plan. Manage it under My subscriptions.');
        }

        // Abandon earlier unpaid attempts for the same plan.
        Subscription::query()->where('user_id', $user->id)->where('subscription_plan_id', $plan->id)
            ->where('status', Subscription::STATUS_PENDING)
            ->update(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'Superseded by a new checkout']);

        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'kind' => Subscription::KIND_RECURRING,
            'gateway' => $gateway ?? 'pending',
            'status' => Subscription::STATUS_PENDING,
            'amount' => $plan->amount,
            'frequency' => $plan->frequency,
            'cycles' => (int) $plan->cycles,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);

        try {
            [$intent, $token] = $this->startIntent($user, $sub, $plan, $gateway, $channel, $idempotencyKey);
        } catch (\Throwable $e) {
            $sub->delete();
            throw $e;
        }
        $sub->update(['gateway' => $intent->gateway]);

        return [$sub->fresh('plan'), $intent, $token];
    }

    /** @return array{0: PaymentIntent, 1: string} */
    protected function startIntent(User $user, Subscription $sub, SubscriptionPlan $plan, ?string $gateway, string $channel, ?string $idempotencyKey): array
    {
        return $this->payments->start(new PayableIntent(
            user: $user,
            payable: $sub,
            service: 'subscription',
            amount: (float) $plan->amount,
            description: 'PromptAid '.$plan->name.' ('.$plan->frequencyLabel().')',
            gateway: $gateway,
            channel: $channel,
            idempotencyKey: $idempotencyKey,
            metadata: ['payfast_subscription' => [
                'type' => 1,
                'billing_date' => now('Africa/Johannesburg')->toDateString(),
                'recurring_amount' => (float) $plan->amount,
                'frequency' => SubscriptionPlan::FREQUENCIES[$plan->frequency] ?? 3,
                'cycles' => (int) $plan->cycles,
            ]],
        ));
    }

    public function activate(Subscription $sub, PaymentIntent $intent, ?string $token): void
    {
        if ($sub->status === Subscription::STATUS_ACTIVE) {
            return;
        }
        $sub->update([
            'status' => Subscription::STATUS_ACTIVE,
            'gateway' => $intent->gateway,
            'token' => $token ?: $sub->token,
            'started_at' => $sub->started_at ?? now(),
            'last_paid_at' => now(),
            'cycles_completed' => max(1, (int) $sub->cycles_completed),
            'next_billing_at' => SubscriptionPlan::advance((string) $sub->frequency, now()),
        ]);
    }

    /** A renewal ITN: record a paid intent for this cycle and roll the dates forward. */
    public function recordRenewal(Subscription $sub, float $amount, string $gatewayRef, string $gateway): string
    {
        if (PaymentIntent::query()->where('gateway', $gateway)->where('gateway_ref', $gatewayRef)->exists()) {
            return 'renewal_duplicate';
        }
        if (abs($amount - (float) $sub->amount) > 0.01) {
            return 'renewal_amount_mismatch';
        }
        PaymentIntent::query()->create([
            'user_id' => $sub->user_id,
            'payable_type' => $sub->getMorphClass(),
            'payable_id' => $sub->id,
            'amount' => $amount,
            'gateway' => $gateway,
            'channel' => 'server',
            'status' => PaymentIntent::STATUS_PAID,
            'gateway_ref' => $gatewayRef,
            'description' => 'Renewal: '.($sub->plan?->name ?? 'subscription'),
            'paid_at' => now(),
            'metadata' => ['renewal' => true],
        ]);
        $completed = (int) $sub->cycles_completed + 1;
        $done = $sub->cycles > 0 && $completed >= $sub->cycles;
        $sub->update([
            'cycles_completed' => $completed,
            'last_paid_at' => now(),
            'next_billing_at' => $done ? null : SubscriptionPlan::advance((string) $sub->frequency, now()),
            'status' => $done ? Subscription::STATUS_CANCELLED : ($sub->status === Subscription::STATUS_PAUSED ? Subscription::STATUS_PAUSED : Subscription::STATUS_ACTIVE),
            'cancelled_at' => $done ? now() : $sub->cancelled_at,
            'cancel_reason' => $done ? 'All billing cycles completed' : $sub->cancel_reason,
        ]);

        return 'renewal_paid';
    }

    public function markCancelledByGateway(Subscription $sub): void
    {
        if ($sub->status !== Subscription::STATUS_CANCELLED) {
            $sub->update(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'next_billing_at' => null, 'cancel_reason' => $sub->cancel_reason ?: 'Cancelled at the gateway']);
        }
    }

    public function cancel(Subscription $sub, string $reason = 'Cancelled by user'): Subscription
    {
        if ($sub->status === Subscription::STATUS_CANCELLED) {
            return $sub;
        }
        if ($sub->status !== Subscription::STATUS_PENDING) {
            $this->remote($sub, 'cancel');
        }
        $sub->update(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'next_billing_at' => null, 'cancel_reason' => mb_substr($reason, 0, 255)]);

        return $sub->fresh('plan');
    }

    public function pause(Subscription $sub): Subscription
    {
        if ($sub->status !== Subscription::STATUS_ACTIVE || $sub->kind !== Subscription::KIND_RECURRING) {
            throw new RuntimeException('Only active subscriptions can be paused.');
        }
        $this->remote($sub, 'pause', ['cycles' => 1]);
        $sub->update(['status' => Subscription::STATUS_PAUSED, 'paused_at' => now()]);

        return $sub->fresh('plan');
    }

    public function resume(Subscription $sub): Subscription
    {
        if ($sub->status !== Subscription::STATUS_PAUSED) {
            throw new RuntimeException('This subscription is not paused.');
        }
        $this->remote($sub, 'unpause');
        $sub->update(['status' => Subscription::STATUS_ACTIVE, 'paused_at' => null]);

        return $sub->fresh('plan');
    }

    /** Pull the latest state from PayFast (admin "Sync" action). */
    public function fetchRemote(Subscription $sub): array
    {
        if ($sub->gateway !== 'payfast' || ! $sub->token) {
            return ['success' => false, 'message' => 'Not a PayFast subscription with a token.'];
        }

        return $this->gateways->payfast()->subscriptionAction($sub->token, 'fetch');
    }

    /** Admin: change amount / cycles / frequency / run date at PayFast. */
    public function updateRemote(Subscription $sub, array $changes): array
    {
        if ($sub->gateway !== 'payfast' || ! $sub->token) {
            return ['success' => false, 'message' => 'Not a PayFast subscription with a token.'];
        }
        $body = array_filter([
            'cycles' => $changes['cycles'] ?? null,
            'frequency' => isset($changes['frequency']) ? (SubscriptionPlan::FREQUENCIES[$changes['frequency']] ?? null) : null,
            'run_date' => $changes['run_date'] ?? null,
            'amount' => isset($changes['amount']) ? (int) round(((float) $changes['amount']) * 100) : null,
        ], fn ($v) => $v !== null && $v !== '');
        $res = $this->gateways->payfast()->subscriptionAction($sub->token, 'update', $body);
        if ($res['success']) {
            $sub->update(array_filter([
                'cycles' => $changes['cycles'] ?? null,
                'frequency' => $changes['frequency'] ?? null,
                'amount' => $changes['amount'] ?? null,
                'next_billing_at' => $changes['run_date'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));
        }

        return $res;
    }

    protected function remote(Subscription $sub, string $action, array $body = []): void
    {
        if ($sub->gateway === 'payfast' && $sub->token) {
            $res = $this->gateways->payfast()->subscriptionAction($sub->token, $action, $body);
            PaymentEvent::query()->create([
                'gateway' => 'payfast', 'event_type' => 'api_subscription_'.$action, 'reference' => 'SUB-'.$sub->id,
                'verified' => true, 'verification_note' => 'Outbound API call', 'outcome' => $res['success'] ? 'ok' : 'error',
                'payload' => ['message' => $res['message']],
            ]);
            if (! $res['success']) {
                throw new RuntimeException('PayFast could not '.$action.' this subscription right now. '.$res['message']);
            }
        }
        // Mock / tokenless subscriptions are local only.
    }

    public function storeCardToken(User $user, string $gateway, string $token): Subscription
    {
        return DB::transaction(function () use ($user, $gateway, $token) {
            $row = Subscription::query()->where('user_id', $user->id)->where('kind', Subscription::KIND_CARD_TOKEN)
                ->where('gateway', $gateway)->lockForUpdate()->first();
            $data = ['token' => $token, 'status' => Subscription::STATUS_ACTIVE, 'started_at' => now(), 'cancelled_at' => null];
            if ($row) {
                $row->update($data);

                return $row;
            }

            return Subscription::query()->create($data + [
                'user_id' => $user->id, 'kind' => Subscription::KIND_CARD_TOKEN, 'gateway' => $gateway, 'amount' => 0,
            ]);
        });
    }

    public function cardTokenFor(User $user): ?Subscription
    {
        $configured = array_keys($this->gateways->configured());

        return Subscription::query()->where('user_id', $user->id)->where('kind', Subscription::KIND_CARD_TOKEN)
            ->where('status', Subscription::STATUS_ACTIVE)->whereNotNull('token')
            ->whereIn('gateway', array_intersect($configured, ['payfast', 'paystack']))
            ->latest('updated_at')->first();
    }

    /** Benefit hook: best active member discount (%) for a plan scope. */
    public function discountPercentFor(?User $user, string $scope): float
    {
        if (! $user) {
            return 0.0;
        }

        return (float) (Subscription::query()
            ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->where('subscriptions.user_id', $user->id)
            ->where('subscriptions.kind', Subscription::KIND_RECURRING)
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->where('subscription_plans.scope', $scope)
            ->max('subscription_plans.discount_percent') ?? 0);
    }
}
