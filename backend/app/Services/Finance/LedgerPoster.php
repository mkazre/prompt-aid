<?php

namespace App\Services\Finance;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\RideCharge;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Turns money events into ledger lines.
 *
 * Capture of gross G (cents) for a provider P under rule (percent, fixed, fee):
 *   fee  = min(patient fee, G)          → platform  "fee"         +fee
 *   base = G − fee                      → P         "earning"     +base
 *   commission = round(base×%)+fixed    → P         "commission"  −commission
 *                                         platform  "commission"  +commission
 *   cash payments (provider kept the cash) add P "cash_collected" −G,
 *   so P's balance becomes −(commission + fee): what P owes the platform.
 * Nobody to attribute (subscriptions, consult cancelled before a doctor was
 * assigned, …): platform "earning" +G.
 *
 * Refunds reverse each component proportionally. Reversals are computed
 * from the CUMULATIVE amount refunded against the captured part, so a
 * re-delivered event or a re-run backfill posts nothing new, and a full
 * refund reverses every component exactly (no cent drift).
 *
 * Group keys: "pi:{intent}" (online payments, via PaymentCaptured /
 * PaymentRefunded) and "payment:{id}", "order:{id}", "ride_charge:{id}"
 * for payments that never went through a payment intent (test-mode
 * legacy charges, cash, medical-aid remittances).
 */
class LedgerPoster
{
    public function __construct(
        protected LedgerService $ledger,
        protected CommissionResolver $resolver,
        protected Attribution $attribution,
    ) {}

    // ------------------------------------------------------------------
    // Online payments (PaymentCaptured / PaymentRefunded)
    // ------------------------------------------------------------------

    public function capturedIntent(PaymentIntent $intent, ?float $amount = null, ?CarbonInterface $at = null): int
    {
        $gross = Money::cents($amount ?? (float) ($intent->captured_amount ?? $intent->amount));

        return $this->postCapture('pi:'.$intent->id, 'payment_intent', (int) $intent->id, $intent->payable, $gross, false,
            $at ?? now(), ['gateway' => $intent->gateway, 'reference' => $intent->reference, 'payable' => $intent->payableAlias(), 'payable_id' => $intent->payable_id]);
    }

    public function refundedIntent(PaymentIntent $intent, ?CarbonInterface $at = null, string $reason = 'Refund'): int
    {
        $intent = $intent->fresh() ?? $intent;
        $amount = Money::cents((string) $intent->amount);
        $captured = $intent->captured_amount !== null
            ? Money::cents((string) $intent->captured_amount)
            : (in_array($intent->status, [PaymentIntent::STATUS_PAID, PaymentIntent::STATUS_REFUNDED], true) ? $amount : 0);
        if ($captured <= 0) {
            return 0;
        }
        $uncaptured = max(0, $amount - $captured);
        $target = max(0, min(Money::cents((string) $intent->refunded_amount) - $uncaptured, $captured));

        return $this->reverseGroup('pi:'.$intent->id, $target, $at ?? now(), $reason);
    }

    // ------------------------------------------------------------------
    // Payments that bypass payment intents (test-mode legacy charges, cash, medical aid)
    // ------------------------------------------------------------------

    public function legacyPayment(Payment $p): int
    {
        if ($this->intentBacked($p->gateway_ref)) {
            return 0;
        }
        $group = 'payment:'.$p->id;
        $gross = Money::cents((string) $p->amount);
        if ($p->status === 'success') {
            return $this->postCapture($group, 'payment', (int) $p->id, $p->invoice, $gross, $p->method === 'cash', $p->created_at ?? now(),
                ['method' => $p->method, 'gateway' => $p->gateway, 'reference' => $p->gateway_ref]);
        }
        if ($p->status === 'refunded') {
            $n = $this->postCapture($group, 'payment', (int) $p->id, $p->invoice, $gross, $p->method === 'cash', $p->created_at ?? now(),
                ['method' => $p->method, 'gateway' => $p->gateway, 'reference' => $p->gateway_ref]);

            return $n + $this->reverseGroup($group, $gross, $p->updated_at ?? now(), 'Payment refunded');
        }

        return 0;
    }

    public function legacyOrder(Order $o): int
    {
        if (! in_array($o->payment_status, ['paid', 'refunded'], true)) {
            return 0;
        }
        $viaIntent = PaymentIntent::query()->where('payable_type', Order::class)->where('payable_id', $o->id)
            ->whereIn('status', [PaymentIntent::STATUS_PAID, PaymentIntent::STATUS_REFUNDED])->exists();
        if ($viaIntent) {
            return 0;
        }
        $group = 'order:'.$o->id;
        $gross = Money::cents((string) $o->total);
        $n = $this->postCapture($group, 'order', (int) $o->id, $o, $gross, false, $o->updated_at ?? now(), ['reference' => $o->order_no]);
        if ($o->payment_status === 'refunded') {
            $n += $this->reverseGroup($group, $gross, now(), 'Order refunded');
        }

        return $n;
    }

    public function legacyRideCharge(RideCharge $c): int
    {
        if (! in_array($c->status, [RideCharge::STATUS_PAID, RideCharge::STATUS_REFUNDED], true) || $this->intentBacked($c->gateway_ref)) {
            return 0;
        }
        $group = 'ride_charge:'.$c->id;
        $gross = Money::cents((string) $c->amount);
        $n = $this->postCapture($group, 'ride_charge', (int) $c->id, $c, $gross, $c->method === 'cash', $c->created_at ?? now(),
            ['method' => $c->method, 'reference' => $c->gateway_ref, 'type' => $c->type]);
        if ($c->status === RideCharge::STATUS_REFUNDED) {
            $n += $this->reverseGroup($group, $gross, $c->refunded_at ?? now(), 'Ride charge refunded');
        }

        return $n;
    }

    /** True when a gateway reference belongs to an online payment intent (posted via the events instead). */
    public function intentBacked(?string $ref): bool
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return false;
        }
        $base = str_ends_with($ref, '-CAP') ? substr($ref, 0, -4) : $ref;

        return PaymentIntent::query()->where(fn ($q) => $q->whereIn('gateway_ref', [$ref, $base])->orWhereIn('reference', [$ref, $base]))->exists();
    }

    // ------------------------------------------------------------------
    // Core
    // ------------------------------------------------------------------

    public function postCapture(string $group, string $sourceType, int $sourceId, ?Model $payable, int $gross, bool $cash, CarbonInterface $at, array $meta = []): int
    {
        if ($gross <= 0) {
            return 0;
        }
        if (LedgerEntry::query()->where('group_key', $group)->where('entry_type', '!=', 'refund_reversal')->exists()) {
            return 0; // already posted (re-delivered event / backfill re-run)
        }
        $at = Carbon::instance($at);
        $attr = $this->attribution->for($payable);
        $service = $attr['service'];
        $base = ['source_type' => $sourceType, 'source_id' => $sourceId, 'service_type' => $service, 'group_key' => $group, 'created_at' => $at];

        if (! $attr['payee_type']) {
            $m = $meta + $attr['meta'] + ['split' => ['gross' => $gross, 'fee' => 0, 'base' => 0, 'commission' => 0, 'net' => 0], 'payee' => null, 'cash' => false];

            return $this->ledger->post([
                $base + ['account_type' => 'platform', 'account_id' => 0, 'entry_type' => 'earning', 'component' => 'earning', 'amount_cents' => $gross,
                    'idempotency_key' => $group.':platform:earning', 'meta' => $m, 'description' => $this->describe($service, $meta, $attr['meta']).' (no provider — platform revenue)'],
            ]);
        }

        $rule = $this->resolver->resolve($service, CommissionResolver::scopesFor($service, $attr['ids']), $at);
        $split = $this->resolver->split($gross, $rule, $attr['commission_cents']);
        $payeeType = $attr['payee_type'];
        $payeeId = (int) $attr['payee_id'];
        $m = $meta + $attr['meta'] + [
            'split' => $split,
            'rule' => ['percent' => $rule['percent'], 'fixed' => Money::toDecimal($rule['fixed_cents']), 'patient_fee' => Money::toDecimal($rule['fee_cents']),
                'rule_ids' => $rule['rule_ids'], 'from' => $rule['from'], 'commission_from_order' => $attr['commission_cents'] !== null],
            'payee' => ['type' => $payeeType, 'id' => $payeeId],
            'cash' => $cash,
        ];
        $desc = $this->describe($service, $meta, $attr['meta']);

        $lines = [
            $base + ['account_type' => $payeeType, 'account_id' => $payeeId, 'entry_type' => 'earning', 'component' => 'earning', 'amount_cents' => $split['base'],
                'idempotency_key' => $group.':payee:earning', 'meta' => $m, 'description' => $desc, 'allow_zero' => true],
            $base + ['account_type' => $payeeType, 'account_id' => $payeeId, 'entry_type' => 'commission', 'component' => 'commission', 'amount_cents' => -$split['commission'],
                'idempotency_key' => $group.':payee:commission', 'meta' => ['percent' => $rule['percent']], 'description' => 'Prompt Aid commission — '.$desc],
            $base + ['account_type' => 'platform', 'account_id' => 0, 'entry_type' => 'commission', 'component' => 'commission', 'amount_cents' => $split['commission'],
                'idempotency_key' => $group.':platform:commission', 'meta' => ['payee' => $m['payee'], 'percent' => $rule['percent']], 'description' => 'Commission — '.$desc],
            $base + ['account_type' => 'platform', 'account_id' => 0, 'entry_type' => 'fee', 'component' => 'fee', 'amount_cents' => $split['fee'],
                'idempotency_key' => $group.':platform:fee', 'meta' => ['payee' => $m['payee']], 'description' => 'Patient fee — '.$desc],
        ];
        if ($cash) {
            $lines[] = $base + ['account_type' => $payeeType, 'account_id' => $payeeId, 'entry_type' => 'cash_collected', 'component' => 'cash', 'amount_cents' => -$gross,
                'idempotency_key' => $group.':payee:cash', 'meta' => null, 'description' => 'Cash collected from the patient — '.$desc];
        }

        return $this->ledger->post($lines);
    }

    /** Bring the reversals of a capture group up to $targetCents refunded (cumulative). */
    public function reverseGroup(string $group, int $targetCents, CarbonInterface $at, string $reason = 'Refund'): int
    {
        $rows = LedgerEntry::query()->where('group_key', $group)->orderBy('id')->get();
        $first = $rows->first(fn (LedgerEntry $r) => $r->entry_type !== 'refund_reversal' && is_array($r->meta) && isset($r->meta['split']));
        if (! $first) {
            return 0;
        }
        $split = $first->meta['split'];
        $gross = (int) $split['gross'];
        if ($gross <= 0) {
            return 0;
        }
        $R = max(0, min($targetCents, $gross));
        $payee = $first->meta['payee'] ?? null;
        $cash = ! empty($first->meta['cash']);

        $already = [];
        foreach ($rows->where('entry_type', 'refund_reversal') as $r) {
            $k = ($r->account_type === 'platform' ? 'platform' : 'payee').':'.$r->component;
            $already[$k] = ($already[$k] ?? 0) + Money::cents((string) $r->amount);
        }

        $targets = [];
        if ($payee) {
            $feeR = Money::mulDiv((int) $split['fee'], $R, $gross);
            $commR = Money::mulDiv((int) $split['commission'], $R, $gross);
            $baseR = $R - $feeR;
            $targets['payee:earning'] = -$baseR;
            $targets['payee:commission'] = $commR;
            if ($cash) {
                $targets['payee:cash'] = $R;
            }
            $targets['platform:commission'] = -$commR;
            $targets['platform:fee'] = -$feeR;
        } else {
            $targets['platform:earning'] = -$R;
        }

        $at = Carbon::instance($at);
        $lines = [];
        foreach ($targets as $k => $target) {
            $delta = $target - ($already[$k] ?? 0);
            if ($delta === 0) {
                continue;
            }
            [$who, $component] = explode(':', $k);
            $lines[] = [
                'account_type' => $who === 'platform' ? 'platform' : $payee['type'],
                'account_id' => $who === 'platform' ? 0 : (int) $payee['id'],
                'entry_type' => 'refund_reversal', 'component' => $component, 'amount_cents' => $delta,
                'source_type' => $first->source_type, 'source_id' => $first->source_id, 'service_type' => $first->service_type,
                'group_key' => $group, 'created_at' => $at,
                'idempotency_key' => 'rev:'.$group.':'.$R.':'.$k,
                'meta' => ['refunded_total_cents' => $R, 'reason' => mb_substr($reason, 0, 120)],
                'description' => 'Refund ('.$component.') — '.mb_substr($reason, 0, 120),
            ];
        }

        return $this->ledger->post($lines);
    }

    protected function describe(string $service, array $meta, array $attrMeta): string
    {
        $label = match ($service) {
            'consult' => 'Consult',
            'appointment' => 'Appointment',
            'shuttle' => 'Shuttle '.str_replace('_', ' ', (string) ($attrMeta['charge_type'] ?? $meta['type'] ?? 'fare')),
            'pharmacy' => 'Pharmacy order',
            'lab' => 'Lab booking',
            'subscription' => 'Subscription',
            default => 'Payment',
        };
        $ref = $attrMeta['ride_ref'] ?? $attrMeta['order_no'] ?? $meta['reference'] ?? null;

        return trim($label.($ref ? ' '.$ref : ''));
    }
}
