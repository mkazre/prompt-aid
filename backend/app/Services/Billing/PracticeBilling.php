<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Models\PracticeBillingEvent;
use App\Models\PracticeInvoice;
use App\Models\PracticePlan;
use App\Models\PracticeSubscription as Sub;
use App\Models\User;
use App\Services\Finance\LedgerPoster;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\PayableIntent;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use App\Support\PaymentSettings;
use App\Support\PracticeBillingSettings as S;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Practice subscription lifecycle (B2B platform fee).
 *
 *   trial ──pay──▶ active ──renewal ITN──▶ active (period advanced, invoice, ledger)
 *     │              │
 *     └─trial ends───┴─renewal missed──▶ past_due ──retry window──▶ grace ──grace days──▶ suspended
 *                                          (daily reminders: in-app + email)        (gate closes when enforced)
 *
 * Payments reuse the online payments layer: the first payment is a PaymentIntent
 * on the PracticeSubscription with PayFast subscription_type=1; PayFast renewal
 * ITNs reuse the first intent's m_payment_id and land in recordRenewal() via
 * PaymentService::apply(). Without gateway credentials the mock gateway runs the
 * same flow, and admins can "simulate renewal" outside production.
 */
class PracticeBilling
{
    public function __construct(
        protected PaymentService $payments,
        protected GatewayManager $gateways,
    ) {}

    // ------------------------------------------------------------------
    // Subscribers
    // ------------------------------------------------------------------

    /** @return array{0: string, 1: int} */
    public static function key(Clinic|DoctorProfile $p): array
    {
        return $p instanceof Clinic ? [Sub::TYPE_CLINIC, (int) $p->id] : [Sub::TYPE_DOCTOR, (int) $p->id];
    }

    public function rowFor(Clinic|DoctorProfile $p): ?Sub
    {
        [$type, $id] = self::key($p);

        return Sub::query()->with('plan')->where('subscriber_type', $type)->where('subscriber_id', $id)->first();
    }

    public static function resolveSubscriber(string $type, int $id): Clinic|DoctorProfile|null
    {
        return match ($type) {
            Sub::TYPE_CLINIC => Clinic::query()->find($id),
            Sub::TYPE_DOCTOR => DoctorProfile::query()->with('user')->find($id),
            default => null,
        };
    }

    public static function doctorCount(Clinic|DoctorProfile|null $p): int
    {
        return match (true) {
            $p instanceof Clinic => (int) $p->doctors()->count(),
            $p instanceof DoctorProfile => 1,
            default => 1,
        };
    }

    /**
     * Practices whose billing this user manages: clinics they administer and,
     * for a doctor with no clinic, their own independent practice.
     *
     * @return array<int, array{type: string, id: int, label: string}>
     */
    public static function managedBy(?User $user): array
    {
        if (! $user) {
            return [];
        }
        $out = [];
        if ($user->isClinicAdmin() || $user->isSuperAdmin()) {
            foreach ($user->clinicsAdministered()->orderBy('name')->get(['id', 'name']) as $c) {
                $out[] = ['type' => Sub::TYPE_CLINIC, 'id' => (int) $c->id, 'label' => (string) $c->name];
            }
        }
        if ($user->isDoctor() && ($doc = $user->doctorProfile)) {
            if (! $doc->clinics()->exists()) {
                $out[] = ['type' => Sub::TYPE_DOCTOR, 'id' => (int) $doc->id, 'label' => 'My practice (independent)'];
            }
        }

        return $out;
    }

    public static function canManage(?User $user, Sub $row): bool
    {
        foreach (self::managedBy($user) as $m) {
            if ($m['type'] === $row->subscriber_type && $m['id'] === (int) $row->subscriber_id) {
                return true;
            }
        }

        return false;
    }

    protected function ensureRow(Clinic|DoctorProfile $p): Sub
    {
        [$type, $id] = self::key($p);

        return Sub::query()->firstOrCreate(
            ['subscriber_type' => $type, 'subscriber_id' => $id],
            ['status' => Sub::STATUS_INCOMPLETE, 'seats' => 1, 'amount_cents' => 0],
        );
    }

    protected function assertPlanFits(PracticePlan $plan, string $type): void
    {
        if (! $plan->is_active) {
            throw new RuntimeException('This plan is not available.');
        }
        if (! in_array($plan->audience, ['any', $type], true)) {
            throw new RuntimeException($type === Sub::TYPE_CLINIC ? 'This plan is for independent doctors only.' : 'This plan is for clinics only.');
        }
    }

    // ------------------------------------------------------------------
    // Practice-side actions
    // ------------------------------------------------------------------

    /** Free trial on a plan (once per practice). */
    public function startTrial(Clinic|DoctorProfile $p, PracticePlan $plan, ?User $by = null, ?int $days = null, bool $admin = false): Sub
    {
        [$type] = self::key($p);
        $this->assertPlanFits($plan, $type);
        $days = $days ?? (int) $plan->trial_days;
        if ($days <= 0) {
            throw new RuntimeException('This plan has no free trial.');
        }

        return DB::transaction(function () use ($p, $plan, $by, $days, $admin) {
            $row = $this->ensureRow($p);
            $row = Sub::query()->lockForUpdate()->findOrFail($row->id);
            if (! $admin && ($row->trial_ends_at !== null || $row->last_paid_at !== null)) {
                throw new RuntimeException('Your practice has already used its free trial.');
            }
            if (in_array($row->status, [Sub::STATUS_ACTIVE, Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE], true) && $row->last_paid_at) {
                throw new RuntimeException('This practice already has a paid subscription.');
            }
            $seats = $plan->seatsFor(self::doctorCount($p));
            $row->update([
                'practice_plan_id' => $plan->id,
                'status' => Sub::STATUS_TRIALING,
                'billing_cycle' => 'monthly',
                'seats' => $seats,
                'amount_cents' => $plan->priceFor('monthly', $seats),
                'trial_ends_at' => now()->addDays($days),
                'next_billing_at' => now()->addDays($days),
                'past_due_at' => null, 'grace_ends_at' => null, 'suspended_at' => null,
                'cancelled_at' => null, 'cancel_at_period_end' => false, 'cancel_reason' => null,
                'reminders_sent' => 0, 'last_reminder_at' => null,
            ]);
            $this->log($row, 'trial_started', $days.'-day free trial on '.$plan->name, $by, ['days' => $days, 'admin' => $admin]);
            if ($admin) {
                $this->audit($row, 'practice_billing.trial_started', [], ['plan' => $plan->name, 'days' => $days], $by);
            }
            PracticeSubscription::flush();

            return $row->fresh('plan');
        });
    }

    /**
     * Start checkout: subscribe (or switch from a lapsed / trial / cancelled state)
     * via PayFast recurring billing (mock when no gateway is configured).
     *
     * @return array{0: Sub, 1: PaymentIntent, 2: string}
     */
    public function checkout(User $by, Clinic|DoctorProfile $p, PracticePlan $plan, string $cycle = 'monthly', ?string $gateway = null, string $channel = 'web'): array
    {
        [$type] = self::key($p);
        $this->assertPlanFits($plan, $type);
        $cycle = $cycle === 'annual' && $plan->hasAnnual() ? 'annual' : 'monthly';
        $row = $this->ensureRow($p);

        if ($row->token && in_array($row->status, [Sub::STATUS_ACTIVE], true) && ! $row->cancel_at_period_end) {
            throw new RuntimeException('Your practice already has an active card subscription. Use "Change plan" instead.');
        }

        $seats = $plan->seatsFor(self::doctorCount($p));
        $amount = $plan->priceFor($cycle, $seats);
        if ($amount < 500) {
            throw new RuntimeException('This plan is priced below the R5.00 card minimum. Ask PromptAid to adjust it.');
        }
        $start = now();
        if ($row->status === Sub::STATUS_TRIALING && $row->trial_ends_at && $row->trial_ends_at->isFuture()) {
            $start = $row->trial_ends_at->copy(); // keep the rest of the free trial
        } elseif ($row->isComped() && $row->comped_until) {
            $start = $row->comped_until->copy();
        } elseif ($row->current_period_end && $row->current_period_end->isFuture() && in_array($row->status, [Sub::STATUS_ACTIVE, Sub::STATUS_CANCELLED, Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE], true)) {
            $start = $row->current_period_end->copy(); // already paid up to here
        }
        $end = self::advance($cycle, $start);
        $checkout = [
            'plan_id' => $plan->id, 'plan_name' => $plan->name, 'cycle' => $cycle, 'seats' => $seats, 'amount_cents' => $amount,
            'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String(), 'by' => $by->id,
        ];
        $row->update(['pending_checkout' => $checkout]);

        try {
            [$intent, $token] = $this->payments->start(new PayableIntent(
                user: $by,
                payable: $row->fresh(),
                service: 'subscription',
                amount: Money::toFloat($amount),
                description: 'PromptAid practice plan: '.$plan->name.' ('.($cycle === 'annual' ? 'yearly' : 'monthly').')',
                gateway: $gateway,
                channel: $channel,
                metadata: [
                    'practice_checkout' => $checkout,
                    'payfast_subscription' => [
                        'type' => 1,
                        // Initial amount is charged now (covers $start → $end); recurring debits start at $end.
                        'billing_date' => $end->copy()->setTimezone('Africa/Johannesburg')->toDateString(),
                        'recurring_amount' => Money::toFloat($amount),
                        'frequency' => $cycle === 'annual' ? 6 : 3,
                        'cycles' => 0,
                    ],
                ],
            ));
        } catch (\Throwable $e) {
            $row->update(['pending_checkout' => null]);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage());
        }
        $row->update(['pending_intent_id' => $intent->id]);
        $this->log($row, 'checkout_started', 'Checkout started: '.$plan->name.' '.Money::format($amount).($cycle === 'annual' ? '/year' : '/month').' via '.$intent->gateway, $by, ['intent' => $intent->uuid]);

        return [$row->fresh('plan'), $intent, $token];
    }

    /** First payment confirmed (verified ITN / status query / mock). Called inside PaymentService::apply's transaction. */
    public function onFirstPayment(Sub $row, PaymentIntent $intent, ?string $token): void
    {
        $row = Sub::query()->lockForUpdate()->find($row->id) ?? $row;
        if ((int) $row->first_intent_id === (int) $intent->id) {
            return; // already applied
        }
        $co = (array) ($intent->meta('practice_checkout') ?: $row->pending_checkout ?: []);
        $plan = PracticePlan::query()->find($co['plan_id'] ?? $row->practice_plan_id);
        $cycle = ($co['cycle'] ?? $row->billing_cycle) === 'annual' ? 'annual' : 'monthly';
        $amount = (int) ($co['amount_cents'] ?? Money::cents((string) $intent->amount));
        $start = isset($co['period_start']) ? Carbon::parse($co['period_start']) : now();
        if ($start->lt(now()->subDay())) {
            $start = now();
        }
        $end = self::advance($cycle, $start);

        $oldToken = $row->token;
        $oldGateway = $row->gateway;
        $row->update([
            'practice_plan_id' => $plan?->id ?? $row->practice_plan_id,
            'status' => Sub::STATUS_ACTIVE,
            'billing_cycle' => $cycle,
            'seats' => (int) ($co['seats'] ?? $row->seats),
            'amount_cents' => $amount,
            'gateway' => $intent->gateway,
            'token' => $token ?: null,
            'first_intent_id' => $intent->id,
            'pending_intent_id' => null,
            'pending_checkout' => null,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'next_billing_at' => $end,
            'last_paid_at' => now(),
            'past_due_at' => null, 'grace_ends_at' => null, 'suspended_at' => null,
            'cancelled_at' => null, 'cancel_at_period_end' => false, 'cancel_reason' => null,
            'reminders_sent' => 0, 'last_reminder_at' => null,
        ]);
        $this->invoice($row, [
            'plan_name' => $plan?->name, 'description' => 'Subscription — first payment', 'period_start' => $start, 'period_end' => $end,
            'seats' => $row->seats, 'amount_cents' => Money::cents((string) $intent->amount), 'status' => 'paid', 'method' => $intent->gateway,
            'payment_intent_id' => $intent->id, 'gateway_ref' => $intent->gateway_ref, 'paid_at' => now(),
        ]);
        $this->log($row, 'activated', 'Subscribed to '.($plan?->name ?? 'plan').' — paid '.Money::format(Money::cents((string) $intent->amount)).' via '.$intent->gateway, null, ['intent' => $intent->uuid]);

        // A previous card agreement (plan switch / re-subscribe) must stop debiting.
        if ($oldToken && $oldToken !== $token && $oldGateway === 'payfast') {
            DB::afterCommit(fn () => $this->remoteCancel($row->fresh(), $oldToken, 'Replaced by a new subscription'));
        }
        DB::afterCommit(function () use ($row) {
            PracticeSubscription::flush();
            $this->notify($row->fresh('plan'), 'Practice subscription active', 'Thank you — your PromptAid practice subscription ('.($row->plan?->name ?? 'plan').') is active until '.$row->current_period_end?->format('d M Y').'.', 'heroicon-o-check-badge', 'success');
        });
    }

    /**
     * A renewal payment (PayFast recurring ITN with a new pf_payment_id, or a
     * simulated renewal). Records a paid intent + invoice, advances the period
     * and posts platform revenue to the ledger. Idempotent on gateway ref.
     */
    public function recordRenewal(Sub $row, float $amount, string $gatewayRef, string $gateway, ?PaymentIntent $via = null): string
    {
        if (PaymentIntent::query()->where('gateway', $gateway)->where('gateway_ref', $gatewayRef)->exists()) {
            return 'renewal_duplicate';
        }
        $row = Sub::query()->lockForUpdate()->find($row->id) ?? $row;
        $cents = Money::cents((string) $amount);
        $intent = PaymentIntent::query()->create([
            'user_id' => $via?->user_id ?? ($row->billingContacts()[0]->id ?? null),
            'payable_type' => $row->getMorphClass(),
            'payable_id' => $row->id,
            'amount' => Money::toDecimal($cents),
            'currency' => 'ZAR',
            'gateway' => $gateway,
            'channel' => 'server',
            'capture_mode' => 'immediate',
            'status' => PaymentIntent::STATUS_PAID,
            'gateway_ref' => mb_substr($gatewayRef, 0, 120),
            'description' => 'Renewal: PromptAid practice plan '.($row->plan?->name ?? ''),
            'paid_at' => now(),
            'captured_at' => now(),
            'captured_amount' => Money::toDecimal($cents),
            'metadata' => ['renewal' => true, 'practice_subscription_id' => $row->id, 'via_intent' => $via?->uuid],
        ]);

        [$start, $end] = $this->nextPeriod($row);
        $row->update([
            'status' => Sub::STATUS_ACTIVE,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'next_billing_at' => $row->cancel_at_period_end ? null : $end,
            'last_paid_at' => now(),
            'past_due_at' => null, 'grace_ends_at' => null, 'suspended_at' => null,
            'reminders_sent' => 0, 'last_reminder_at' => null,
        ]);
        $this->invoice($row, [
            'plan_name' => $row->plan?->name, 'description' => 'Subscription renewal', 'period_start' => $start, 'period_end' => $end,
            'seats' => $row->seats, 'amount_cents' => $cents, 'status' => 'paid', 'method' => $gateway,
            'payment_intent_id' => $intent->id, 'gateway_ref' => $gatewayRef, 'paid_at' => now(),
        ]);
        $flags = [];
        if ($cents !== (int) $row->amount_cents) {
            $flags[] = 'amount differs from plan amount '.Money::format((int) $row->amount_cents);
        }
        if ($via && $row->first_intent_id && (int) $via->id !== (int) $row->first_intent_id) {
            $flags[] = 'debit came from an older card agreement — check it was cancelled at PayFast';
        }
        $this->log($row, 'renewed', 'Renewal received '.Money::format($cents).' via '.$gateway.' — paid until '.$end->format('d M Y').($flags ? ' ('.implode('; ', $flags).')' : ''), null, ['intent' => $intent->uuid, 'ref' => $gatewayRef]);

        DB::afterCommit(function () use ($intent, $cents) {
            PracticeSubscription::flush();
            $this->payments->fireCaptured($intent->fresh(), Money::toFloat($cents)); // → ledger (platform revenue)
        });

        return 'practice_renewal_paid';
    }

    /** A FAILED ITN on the card agreement, or a decline we were told about. */
    public function onPaymentFailed(Sub $row, string $reason = 'The card payment was declined'): void
    {
        $row = $row->fresh();
        if (in_array($row->status, [Sub::STATUS_ACTIVE, Sub::STATUS_TRIALING], true)) {
            $this->enterPastDue($row, $reason);
        } else {
            $this->log($row, 'payment_failed', $reason);
        }
    }

    /** PayFast says the card agreement was cancelled (by the practice, the bank or PayFast). Access runs to the period end. */
    public function onGatewayCancelled(Sub $row): void
    {
        $row = $row->fresh();
        $row->update(['token' => null, 'cancel_at_period_end' => true, 'next_billing_at' => null, 'cancel_reason' => $row->cancel_reason ?: 'Card agreement cancelled at PayFast']);
        $this->log($row, 'gateway_cancelled', 'PayFast cancelled the card agreement — access continues until '.($row->current_period_end?->format('d M Y') ?? 'the period end'));
        DB::afterCommit(fn () => $this->notify($row->fresh('plan'), 'Card subscription cancelled', 'Your PromptAid card subscription was cancelled at PayFast. Subscribe again before '.($row->current_period_end?->format('d M Y') ?? 'your period ends').' to keep receiving patient requests.', 'heroicon-o-exclamation-triangle', 'warning'));
    }

    /** Practice cancels: card agreement stopped now, access until the end of the paid period. */
    public function cancel(Sub $row, ?User $by, string $reason = 'Cancelled by the practice', bool $admin = false): Sub
    {
        if ($row->status === Sub::STATUS_CANCELLED) {
            return $row;
        }
        if ($row->token && $row->gateway === 'payfast') {
            $res = $this->gateways->payfast()->subscriptionAction($row->token, 'cancel');
            $this->paymentLog($row, 'cancel', $res);
            if (! $res['success']) {
                throw new RuntimeException('PayFast could not cancel the card subscription right now. '.$res['message']);
            }
        }
        $paidAhead = $row->current_period_end && $row->current_period_end->isFuture() && $row->last_paid_at;
        $row->update([
            'token' => null,
            'cancel_at_period_end' => $paidAhead,
            'status' => $paidAhead ? $row->status : Sub::STATUS_CANCELLED,
            'cancelled_at' => $paidAhead ? null : now(),
            'next_billing_at' => null,
            'cancel_reason' => mb_substr($reason, 0, 255),
            'pending_checkout' => null, 'pending_intent_id' => null,
        ]);
        $msg = $paidAhead ? 'Cancelled — access until '.$row->current_period_end->format('d M Y') : 'Cancelled';
        $this->log($row, 'cancelled', $msg.' ('.$reason.')', $by);
        if ($admin) {
            $this->audit($row, 'practice_billing.cancelled', [], ['reason' => $reason], $by);
        }
        PracticeSubscription::flush();

        return $row->fresh('plan');
    }

    /** Per-seat plans: bill for the current number of doctors (takes effect on the next debit). */
    public function syncSeats(Sub $row, ?User $by = null): Sub
    {
        $plan = $row->plan;
        if (! $plan) {
            throw new RuntimeException('No plan on this subscription.');
        }
        $seats = $plan->seatsFor(self::doctorCount($row->subscriber()));
        $amount = $plan->priceFor((string) $row->billing_cycle, $seats);
        if ($seats === (int) $row->seats && $amount === (int) $row->amount_cents) {
            return $row;
        }
        $this->remoteAmount($row, $amount, null);
        $old = ['seats' => $row->seats, 'amount_cents' => $row->amount_cents];
        $row->update(['seats' => $seats, 'amount_cents' => $amount]);
        $this->log($row, 'seats_updated', 'Seats '.$old['seats'].' → '.$seats.'; next debit '.Money::format($amount), $by);

        return $row->fresh('plan');
    }

    /** Switch plan / cycle on a running subscription (the new amount applies from the next debit). */
    public function changePlan(Sub $row, PracticePlan $plan, string $cycle, ?User $by, bool $admin = false): Sub
    {
        $this->assertPlanFits($plan, (string) $row->subscriber_type);
        $cycle = $cycle === 'annual' && $plan->hasAnnual() ? 'annual' : 'monthly';
        $seats = $plan->seatsFor(self::doctorCount($row->subscriber()));
        $amount = $plan->priceFor($cycle, $seats);
        if ($row->token && $cycle !== $row->billing_cycle) {
            throw new RuntimeException('Changing between monthly and yearly needs a new card subscription: cancel, then subscribe again.');
        }
        $this->remoteAmount($row, $amount, null);
        $old = ['plan' => $row->plan?->name, 'cycle' => $row->billing_cycle, 'amount_cents' => $row->amount_cents];
        $row->update(['practice_plan_id' => $plan->id, 'billing_cycle' => $cycle, 'seats' => $seats, 'amount_cents' => $amount]);
        $this->log($row, 'plan_changed', 'Plan '.($old['plan'] ?? '—').' → '.$plan->name.' ('.Money::format($amount).($cycle === 'annual' ? '/year' : '/month').')', $by);
        if ($admin) {
            $this->audit($row, 'practice_billing.plan_changed', $old, ['plan' => $plan->name, 'cycle' => $cycle, 'amount_cents' => $amount], $by);
        }
        PracticeSubscription::flush();

        return $row->fresh('plan');
    }

    /** PayFast "update card details" page for this agreement (null when not a PayFast card subscription). */
    public function updateCardUrl(Sub $row, ?string $returnUrl = null): ?string
    {
        if ($row->gateway !== 'payfast' || ! $row->token) {
            return null;
        }
        $host = PaymentSettings::bool('payfast_sandbox') ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';

        return 'https://'.$host.'/eng/recurring/update/'.rawurlencode($row->token).($returnUrl ? '?return='.rawurlencode($returnUrl) : '');
    }

    // ------------------------------------------------------------------
    // Admin overrides (all audited)
    // ------------------------------------------------------------------

    public function adminStart(string $type, int $id, PracticePlan $plan, string $mode, int $days, ?User $by): Sub
    {
        $p = self::resolveSubscriber($type, $id);
        if (! $p) {
            throw new RuntimeException('Practice not found.');
        }
        if ($mode === 'trial') {
            return $this->startTrial($p, $plan, $by, max(1, $days), true);
        }
        // "Invoice / EFT" mode: plan set, waiting for a manual payment.
        $row = $this->ensureRow($p);
        $seats = $plan->seatsFor(self::doctorCount($p));
        $row->update(['practice_plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'seats' => $seats, 'amount_cents' => $plan->priceFor('monthly', $seats)]);
        $this->log($row, 'plan_assigned', 'Plan '.$plan->name.' assigned by admin (awaiting payment)', $by);
        $this->audit($row, 'practice_billing.plan_assigned', [], ['plan' => $plan->name], $by);
        PracticeSubscription::flush();

        return $row->fresh('plan');
    }

    /** Waive (comp) the subscription until a date. */
    public function comp(Sub $row, CarbonInterface $until, string $reason, ?User $by): Sub
    {
        $old = ['comped_until' => $row->comped_until?->toIso8601String(), 'status' => $row->status];
        $updates = ['comped_until' => $until, 'comp_reason' => mb_substr($reason, 0, 255), 'past_due_at' => null, 'grace_ends_at' => null, 'suspended_at' => null, 'reminders_sent' => 0];
        if (in_array($row->status, [Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE, Sub::STATUS_SUSPENDED, Sub::STATUS_INCOMPLETE], true)) {
            $updates['status'] = Sub::STATUS_ACTIVE;
        }
        // Billing resumes when the waiver ends.
        if (! $row->current_period_end || $row->current_period_end->lt($until)) {
            $updates['current_period_end'] = $until;
            $updates['next_billing_at'] = $row->cancel_at_period_end ? null : $until;
        }
        $note = '';
        if ($row->token && $row->gateway === 'payfast') {
            $res = $this->gateways->payfast()->subscriptionAction($row->token, 'update', ['run_date' => Carbon::instance($until)->setTimezone('Africa/Johannesburg')->toDateString()]);
            $this->paymentLog($row, 'update', $res);
            $note = $res['success'] ? ' (PayFast next debit moved to '.Carbon::instance($until)->toDateString().')' : ' (WARNING: PayFast did not move the next debit: '.$res['message'].')';
        }
        $row->update($updates);
        $this->invoice($row, ['plan_name' => $row->plan?->name, 'description' => 'Waived until '.Carbon::instance($until)->format('d M Y').': '.$reason, 'period_start' => now(), 'period_end' => $until,
            'seats' => $row->seats, 'amount_cents' => 0, 'status' => 'waived', 'method' => 'comp', 'recorded_by' => $by?->id, 'paid_at' => now()]);
        $this->log($row, 'comped', 'Waived until '.Carbon::instance($until)->format('d M Y').' — '.$reason.$note, $by);
        $this->audit($row, 'practice_billing.comped', $old, ['comped_until' => Carbon::instance($until)->toIso8601String(), 'reason' => $reason], $by);
        PracticeSubscription::flush();

        return $row->fresh('plan');
    }

    public function extendTrial(Sub $row, int $days, ?User $by): Sub
    {
        if ($row->last_paid_at !== null && $row->status !== Sub::STATUS_TRIALING) {
            throw new RuntimeException('This practice has already paid — use "Waive" to give free time instead.');
        }
        $base = $row->trial_ends_at && $row->trial_ends_at->isFuture() ? $row->trial_ends_at->copy() : now();
        $old = ['trial_ends_at' => $row->trial_ends_at?->toIso8601String(), 'status' => $row->status];
        $row->update(['status' => Sub::STATUS_TRIALING, 'trial_ends_at' => $base->addDays($days), 'next_billing_at' => $base,
            'past_due_at' => null, 'grace_ends_at' => null, 'suspended_at' => null, 'reminders_sent' => 0]);
        $this->log($row, 'trial_extended', 'Trial extended by '.$days.' days to '.$row->trial_ends_at->format('d M Y'), $by);
        $this->audit($row, 'practice_billing.trial_extended', $old, ['trial_ends_at' => $row->trial_ends_at->toIso8601String(), 'days' => $days], $by);
        PracticeSubscription::flush();

        return $row->fresh('plan');
    }

    /** Record an EFT / manual payment for the next period (admin). Posts platform revenue to the ledger. */
    public function markPaidManual(Sub $row, int $amountCents, string $method, ?string $reference, ?User $by, ?string $cycle = null): PracticeInvoice
    {
        if ($amountCents <= 0) {
            throw new RuntimeException('Enter the amount received.');
        }
        if (! $row->practice_plan_id) {
            throw new RuntimeException('Assign a plan first.');
        }
        $ref = trim((string) $reference);
        if ($ref !== '' && PracticeInvoice::query()->where('practice_subscription_id', $row->id)->where('gateway_ref', $ref)->where('status', 'paid')->exists()) {
            throw new RuntimeException('A payment with this reference is already recorded.');
        }

        return DB::transaction(function () use ($row, $amountCents, $method, $ref, $by, $cycle) {
            $row = Sub::query()->lockForUpdate()->findOrFail($row->id);
            if ($cycle) {
                $row->billing_cycle = $cycle === 'annual' ? 'annual' : 'monthly';
            }
            $old = ['status' => $row->status, 'current_period_end' => $row->current_period_end?->toIso8601String()];
            [$start, $end] = $this->nextPeriod($row);
            $row->update([
                'status' => Sub::STATUS_ACTIVE, 'billing_cycle' => $row->billing_cycle,
                'current_period_start' => $start, 'current_period_end' => $end,
                'next_billing_at' => $row->cancel_at_period_end ? null : $end,
                'last_paid_at' => now(), 'gateway' => $row->token ? $row->gateway : $method,
                'past_due_at' => null, 'grace_ends_at' => null, 'suspended_at' => null,
                'cancelled_at' => null, 'reminders_sent' => 0, 'last_reminder_at' => null,
            ]);
            $inv = $this->invoice($row, [
                'plan_name' => $row->plan?->name, 'description' => 'Payment received by '.strtoupper($method).($ref !== '' ? ' — ref '.$ref : ''),
                'period_start' => $start, 'period_end' => $end, 'seats' => $row->seats, 'amount_cents' => $amountCents, 'status' => 'paid',
                'method' => $method, 'gateway_ref' => $ref !== '' ? mb_substr($ref, 0, 120) : null, 'paid_at' => now(), 'recorded_by' => $by?->id,
            ]);
            $this->log($row, 'marked_paid', 'Marked paid '.Money::format($amountCents).' ('.strtoupper($method).($ref !== '' ? ' '.$ref : '').') — paid until '.$end->format('d M Y'), $by);
            $this->audit($row, 'practice_billing.marked_paid', $old, ['amount_cents' => $amountCents, 'method' => $method, 'reference' => $ref, 'paid_until' => $end->toIso8601String()], $by);
            DB::afterCommit(function () use ($inv, $row, $amountCents) {
                PracticeSubscription::flush();
                try {
                    app(LedgerPoster::class)->postCapture('pinv:'.$inv->id, 'practice_invoice', (int) $inv->id, $row, $amountCents, false, now(),
                        ['reference' => $inv->number, 'payable' => 'practice_subscription', 'payable_id' => $row->id, 'gateway' => $inv->method]);
                } catch (\Throwable $e) {
                    Log::error('[PracticeBilling] ledger posting failed: '.$e->getMessage());
                }
            });

            return $inv;
        });
    }

    public function suspend(Sub $row, string $reason, ?User $by): Sub
    {
        $old = ['status' => $row->status];
        $row->update(['status' => Sub::STATUS_SUSPENDED, 'suspended_at' => now(), 'comped_until' => null]);
        $this->log($row, 'suspended', 'Suspended by admin — '.$reason, $by);
        $this->audit($row, 'practice_billing.suspended', $old, ['status' => 'suspended', 'reason' => $reason], $by);
        PracticeSubscription::flush();
        $this->notify($row->fresh('plan'), 'Practice subscription suspended', 'Your PromptAid practice subscription was suspended: '.$reason.'. You will not receive new patient requests until it is reactivated.', 'heroicon-o-no-symbol', 'danger');

        return $row->fresh('plan');
    }

    /** Lift a suspension / failed state. Paid-up → active; otherwise a fresh grace period to pay. */
    public function reactivate(Sub $row, ?User $by): Sub
    {
        $old = ['status' => $row->status];
        $paidUp = ($row->current_period_end && $row->current_period_end->isFuture()) || $row->isComped();
        $grace = now()->addDays(max(1, S::graceDays()));
        $row->update($paidUp
            ? ['status' => Sub::STATUS_ACTIVE, 'suspended_at' => null, 'past_due_at' => null, 'grace_ends_at' => null, 'cancelled_at' => null, 'reminders_sent' => 0]
            : ['status' => Sub::STATUS_GRACE, 'suspended_at' => null, 'past_due_at' => $row->past_due_at ?? now(), 'grace_ends_at' => $grace, 'cancelled_at' => null, 'reminders_sent' => 0]);
        $this->log($row, 'reactivated', $paidUp ? 'Reactivated (paid up)' : 'Reactivated with a grace period until '.$grace->format('d M Y'), $by);
        $this->audit($row, 'practice_billing.reactivated', $old, ['status' => $row->status], $by);
        PracticeSubscription::flush();

        return $row->fresh('plan');
    }

    public function addNote(Sub $row, string $note, ?User $by): void
    {
        $this->log($row, 'note', mb_substr($note, 0, 255), $by);
    }

    /**
     * Test helper: run a renewal through the real renewal path. With a first
     * intent it goes through PaymentService::apply (exactly like a PayFast
     * recurring ITN); otherwise it is recorded directly on the mock gateway.
     */
    public function simulateRenewal(Sub $row, ?User $by): string
    {
        if (! S::simulationAllowed() && ! $this->gateways->mockActive()) {
            throw new RuntimeException('Simulated renewals are switched off in production.');
        }
        if ((int) $row->amount_cents <= 0) {
            throw new RuntimeException('This subscription has no amount to renew.');
        }
        $ref = 'SIM-'.strtoupper(Str::random(12));
        $first = $row->first_intent_id ? PaymentIntent::query()->find($row->first_intent_id) : null;
        PaymentEvent::query()->create([
            'payment_intent_id' => $first?->id, 'gateway' => $first?->gateway ?? 'mock', 'event_type' => 'simulated_renewal',
            'reference' => $first?->reference ?? 'PSUB-'.$row->id, 'verified' => true, 'verification_note' => 'Simulated renewal by admin (test)',
            'outcome' => 'pending', 'payload' => ['amount' => Money::toDecimal((int) $row->amount_cents), 'by' => $by?->id],
        ]);
        $outcome = $first && $first->status === PaymentIntent::STATUS_PAID
            ? $this->payments->apply($first, 'paid', $ref, Money::toFloat((int) $row->amount_cents))
            : DB::transaction(fn () => $this->recordRenewal($row, Money::toFloat((int) $row->amount_cents), $ref, 'mock'));
        PaymentEvent::query()->where('event_type', 'simulated_renewal')->where('outcome', 'pending')->latest('id')->limit(1)->update(['outcome' => $outcome]);
        $this->audit($row, 'practice_billing.simulated_renewal', [], ['outcome' => $outcome, 'ref' => $ref], $by);

        return $outcome;
    }

    // ------------------------------------------------------------------
    // Lifecycle tick
    // ------------------------------------------------------------------

    /** Evaluate every live subscription (Tick job, hourly; idempotent). @return int rows changed */
    public function tick(?CarbonInterface $now = null): int
    {
        $now = $now ? Carbon::instance($now) : now();
        $n = 0;
        Sub::query()->with('plan')
            ->whereIn('status', [Sub::STATUS_TRIALING, Sub::STATUS_ACTIVE, Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE])
            ->orderBy('id')->chunkById(200, function ($rows) use (&$n, $now) {
                foreach ($rows as $row) {
                    try {
                        $n += $this->evaluate($row, $now) ? 1 : 0;
                    } catch (\Throwable $e) {
                        Log::error('[PracticeBilling] evaluate #'.$row->id.' failed: '.$e->getMessage());
                    }
                }
            });
        if ($n > 0) {
            PracticeSubscription::flush();
        }

        return $n;
    }

    /** One subscription's state machine step. Returns true when the status changed. */
    public function evaluate(Sub $row, ?CarbonInterface $now = null): bool
    {
        $now = $now ? Carbon::instance($now) : now();
        $before = $row->status;
        if ($row->comped_until !== null && $row->comped_until->gt($now)) {
            return false;
        }

        switch ($row->status) {
            case Sub::STATUS_TRIALING:
                if ($row->trial_ends_at && $row->trial_ends_at->lte($now)) {
                    $this->enterPastDue($row, 'Your free trial has ended', $now);
                } else {
                    $this->maybeTrialReminder($row, $now);
                }
                break;

            case Sub::STATUS_ACTIVE:
                $end = $row->current_period_end;
                if ($end && $end->lte($now)) {
                    if ($row->cancel_at_period_end) {
                        $this->finishCancellation($row, $now);
                    } else {
                        // The renewal debit has not arrived (yet).
                        $this->enterPastDue($row, $row->token ? 'Your monthly card payment did not go through' : 'Your subscription period has ended and no payment was received', $now);
                    }
                }
                break;

            case Sub::STATUS_PAST_DUE:
                if ($row->cancel_at_period_end) {
                    $this->finishCancellation($row, $now);
                    break;
                }
                $retryEnds = ($row->past_due_at ?? $now)->copy()->addHours(S::retryHours());
                if ($retryEnds->lte($now)) {
                    $row->update(['status' => Sub::STATUS_GRACE, 'grace_ends_at' => $row->grace_ends_at ?? $retryEnds->copy()->addDays(S::graceDays())]);
                    $this->log($row, 'grace', 'Grace period until '.$row->grace_ends_at->format('d M Y H:i'));
                }
                $this->maybeDunningReminder($row->fresh('plan'), $now);
                break;

            case Sub::STATUS_GRACE:
                if ($row->grace_ends_at === null || $row->grace_ends_at->lte($now)) {
                    $row->update(['status' => Sub::STATUS_SUSPENDED, 'suspended_at' => $now]);
                    $this->log($row, 'suspended', 'Suspended automatically: no payment by the end of the grace period');
                    $this->notify($row->fresh('plan'), 'Practice subscription suspended',
                        'We did not receive your PromptAid subscription payment, so your practice is suspended'.(S::enforced() ? ' and will not receive new patient requests' : '').'. Pay now to reactivate immediately.',
                        'heroicon-o-no-symbol', 'danger');
                } else {
                    $this->maybeDunningReminder($row, $now);
                }
                break;
        }

        return $row->fresh()->status !== $before;
    }

    protected function enterPastDue(Sub $row, string $reason, ?CarbonInterface $now = null): void
    {
        $now = $now ? Carbon::instance($now) : now();
        $retryEnds = $now->copy()->addHours(S::retryHours());
        $row->update([
            'status' => Sub::STATUS_PAST_DUE,
            'past_due_at' => $now,
            'grace_ends_at' => $retryEnds->copy()->addDays(S::graceDays()),
            'reminders_sent' => 0,
        ]);
        $this->log($row, 'past_due', $reason.' — payment due; grace until '.$row->grace_ends_at->format('d M Y'));
        $this->sendDunning($row->fresh('plan'), $reason, $now);
    }

    protected function finishCancellation(Sub $row, CarbonInterface $now): void
    {
        $row->update(['status' => Sub::STATUS_CANCELLED, 'cancelled_at' => $now, 'next_billing_at' => null, 'past_due_at' => null, 'grace_ends_at' => null]);
        $this->log($row, 'cancelled', 'Subscription ended (cancelled at period end)');
        $this->notify($row->fresh('plan'), 'Practice subscription ended', 'Your PromptAid practice subscription has ended. Subscribe again any time from Practice subscription in the staff panel.', 'heroicon-o-information-circle', 'gray');
    }

    protected function maybeDunningReminder(Sub $row, CarbonInterface $now): void
    {
        if (! S::bool('practice_billing_reminders')) {
            return;
        }
        if ($row->last_reminder_at && $row->last_reminder_at->gt(Carbon::instance($now)->subHours(23))) {
            return;
        }
        $this->sendDunning($row, null, $now);
    }

    protected function sendDunning(Sub $row, ?string $reason, CarbonInterface $now): void
    {
        if (! S::bool('practice_billing_reminders')) {
            return;
        }
        $until = $row->grace_ends_at?->format('d M Y');
        $body = ($reason ? $reason.'. ' : 'Your PromptAid practice subscription payment is overdue. ')
            .'Please '.($row->token ? 'update your card' : 'pay').' before '.($until ?? 'the grace period ends')
            .' to keep receiving patient requests ('.$row->amountLabel().').';
        $this->notify($row, 'Practice subscription: payment needed', $body, 'heroicon-o-credit-card', 'warning', 'Update payment');
        $row->update(['last_reminder_at' => $now, 'reminders_sent' => (int) $row->reminders_sent + 1]);
    }

    protected function maybeTrialReminder(Sub $row, CarbonInterface $now): void
    {
        $days = S::int('practice_billing_trial_reminder_days');
        if ($days <= 0 || ! $row->trial_ends_at || $row->first_intent_id || $row->pending_checkout) {
            return;
        }
        if ($row->current_period_end && $row->current_period_end->gt($row->trial_ends_at)) {
            return; // already paid for after the trial
        }
        if ($row->trial_ends_at->gt(Carbon::instance($now)->addDays($days))) {
            return;
        }
        if ($row->last_reminder_at && $row->last_reminder_at->gt(Carbon::instance($now)->subHours(23))) {
            return;
        }
        $left = max(0, (int) ceil(Carbon::instance($now)->diffInHours($row->trial_ends_at, false) / 24));
        $this->notify($row, 'Your free trial ends '.($left <= 1 ? 'tomorrow' : 'in '.$left.' days'),
            'Subscribe before '.$row->trial_ends_at->format('d M Y').' to keep receiving patient requests on PromptAid. Your card is only charged from the end of the trial.',
            'heroicon-o-clock', 'info', 'Subscribe');
        $row->update(['last_reminder_at' => $now, 'reminders_sent' => (int) $row->reminders_sent + 1]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function advance(string $cycle, CarbonInterface $from): Carbon
    {
        $d = Carbon::instance($from)->copy();

        return $cycle === 'annual' ? $d->addYearNoOverflow() : $d->addMonthNoOverflow();
    }

    /** Period a new payment covers: continuous from the last paid period unless it lapsed long ago. @return array{0: Carbon, 1: Carbon} */
    protected function nextPeriod(Sub $row): array
    {
        $start = $row->current_period_end ? Carbon::instance($row->current_period_end) : now();
        if ($row->status === Sub::STATUS_TRIALING && $row->trial_ends_at && $row->trial_ends_at->isFuture()) {
            $start = Carbon::instance($row->trial_ends_at);
        }
        if ($start->lt(now()->subDays(S::graceDays() + 3)) || $row->status === Sub::STATUS_SUSPENDED || $row->status === Sub::STATUS_CANCELLED || $row->status === Sub::STATUS_INCOMPLETE) {
            $start = now();
        }
        if ($start->gt(now()->addYears(2))) {
            $start = now();
        }

        return [$start->copy(), self::advance((string) $row->billing_cycle, $start)];
    }

    protected function remoteAmount(Sub $row, int $amountCents, ?string $runDate): void
    {
        if ($row->gateway !== 'payfast' || ! $row->token) {
            return;
        }
        $res = $this->gateways->payfast()->subscriptionAction($row->token, 'update', array_filter(['amount' => $amountCents, 'run_date' => $runDate]));
        $this->paymentLog($row, 'update', $res);
        if (! $res['success']) {
            throw new RuntimeException('PayFast could not update the debit amount right now. '.$res['message']);
        }
    }

    protected function remoteCancel(Sub $row, string $token, string $why): void
    {
        try {
            $res = $this->gateways->payfast()->subscriptionAction($token, 'cancel');
            $this->paymentLog($row, 'cancel', $res);
            $this->log($row, 'old_agreement_cancelled', ($res['success'] ? 'Previous card agreement cancelled at PayFast' : 'WARNING: could not cancel the previous card agreement at PayFast — cancel it in the PayFast dashboard ('.$res['message'].')').' — '.$why);
        } catch (\Throwable $e) {
            Log::error('[PracticeBilling] remote cancel failed: '.$e->getMessage());
        }
    }

    protected function paymentLog(Sub $row, string $action, array $res): void
    {
        try {
            PaymentEvent::query()->create([
                'gateway' => 'payfast', 'event_type' => 'api_practice_subscription_'.$action, 'reference' => 'PSUB-'.$row->id,
                'verified' => true, 'verification_note' => 'Outbound API call', 'outcome' => $res['success'] ? 'ok' : 'error',
                'payload' => ['message' => $res['message'] ?? ''],
            ]);
        } catch (\Throwable) {
        }
    }

    protected function invoice(Sub $row, array $data): PracticeInvoice
    {
        return PracticeInvoice::query()->create($data + [
            'practice_subscription_id' => $row->id,
            'number' => 'TMP-'.Str::uuid(),
        ]);
    }

    public function log(Sub $row, string $type, string $message, ?User $by = null, array $data = []): void
    {
        PracticeBillingEvent::query()->create([
            'practice_subscription_id' => $row->id, 'type' => $type, 'message' => mb_substr($message, 0, 255),
            'user_id' => $by?->id, 'data' => $data ?: null, 'created_at' => now(),
        ]);
    }

    protected function audit(Sub $row, string $event, array $old, array $new, ?User $by): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => $by?->id, 'event' => $event, 'auditable_type' => Sub::class, 'auditable_id' => $row->id,
                'old_values' => $old ?: null, 'new_values' => $new + ['practice' => $row->subscriberLabel()], 'ip' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PracticeBilling] audit failed: '.$e->getMessage());
        }
    }

    /** In-app (staff bell + push) and email via the notification dispatcher to the practice's billing contact(s). */
    public function notify(Sub $row, string $title, string $body, string $icon = 'heroicon-o-credit-card', string $color = 'info', string $actionLabel = 'Open'): void
    {
        $url = self::pageUrl();
        foreach ($row->billingContacts() as $user) {
            try {
                \App\Support\StaffNotifier::alert($user, $title, $body, $icon, $color, $url, $actionLabel);
            } catch (\Throwable $e) {
                Log::warning('[PracticeBilling] staff alert failed: '.$e->getMessage());
            }
            try {
                app(\App\Contracts\NotificationDispatcherInterface::class)->email($user, $title, $body."\n\n".($url ?? ''), [
                    'type' => 'billing', 'data' => array_filter(['url' => $url]),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[PracticeBilling] email failed: '.$e->getMessage());
            }
        }
    }

    public static function pageUrl(): ?string
    {
        try {
            return \App\Filament\Pages\PracticeSubscriptionPage::getUrl(panel: 'admin');
        } catch (\Throwable) {
            return url('/staff/practice-subscription');
        }
    }
}
