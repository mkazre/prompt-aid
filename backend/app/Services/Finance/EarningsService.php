<?php

namespace App\Services\Finance;

use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the ledger: provider statements, platform revenue, balances.
 * Shared with the dashboards (DASHBOARDS agent reads summaryFor()).
 * All figures are rand floats rounded to 2 decimals, computed from integer cents.
 */
class EarningsService
{
    public function __construct(protected LedgerService $ledger, protected PayoutService $payouts) {}

    /**
     * Earnings summary for one ledger account over [from, to].
     *
     * @return array{account_type: string, account_id: int, currency: string, from: string, to: string,
     *   gross: float, commission: float, fees: float, refunds: float, adjustments: float, net: float, cash_collected: float,
     *   balance: float, on_hold: float, available: float, in_payout: float, pending_payout: float, paid_out: float, paid_out_total: float,
     *   counts: array{items: int, refunds: int, payouts_paid: int, payouts_open: int}}
     */
    public function summaryFor(string $accountType, int $accountId, Carbon $from, Carbon $to): array
    {
        $from = $from->copy();
        $to = $to->copy();
        $q = fn () => LedgerEntry::query()->where('account_type', $accountType)->where('account_id', $accountId)->whereBetween('created_at', [$from, $to]);

        $by = $q()->select('entry_type', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as n'))->groupBy('entry_type')->get()
            ->mapWithKeys(fn ($r) => [$r->entry_type => ['cents' => Money::cents((string) $r->total), 'n' => (int) $r->n]]);
        $c = fn (string $t) => $by[$t]['cents'] ?? 0;

        if ($accountType === 'platform') {
            $gross = $c('earning') + $c('commission') + $c('fee');
            $commission = $c('commission');
        } else {
            $gross = $c('earning');
            $commission = -$c('commission');
        }
        $refunds = $c('refund_reversal');
        $adjust = $c('adjustment');
        $net = $accountType === 'platform' ? $gross + $refunds + $adjust : $gross - $commission + $refunds + $adjust;

        $avail = $accountType === 'platform'
            ? ['balance' => $this->ledger->balanceCents('platform', 0), 'on_hold' => 0, 'available' => 0]
            : $this->payouts->availability($accountType, $accountId);
        $open = Payout::query()->where('account_type', $accountType)->where('account_id', $accountId)->whereIn('status', ['pending', 'approved']);
        $inPayout = LedgerService::sumCents((clone $open));
        $paid = Payout::query()->where('account_type', $accountType)->where('account_id', $accountId)->where('status', 'paid');
        $paidPeriod = LedgerService::sumCents((clone $paid)->whereBetween('paid_at', [$from, $to]));
        $paidTotal = LedgerService::sumCents(clone $paid);

        $f = fn (int $cents) => Money::toFloat($cents);

        return [
            'account_type' => $accountType,
            'account_id' => $accountId,
            'currency' => 'ZAR',
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'gross' => $f($gross),
            'commission' => $f($commission),
            'fees' => $f($c('fee')),
            'refunds' => $f($refunds),
            'adjustments' => $f($adjust),
            'net' => $f($net),
            'cash_collected' => $f(-$c('cash_collected')),
            'balance' => $f($avail['balance']),
            'on_hold' => $f($avail['on_hold']),
            'available' => $f($avail['available']),
            'in_payout' => $f($inPayout),
            'pending_payout' => $f(max(0, $avail['balance']) + $inPayout),
            'paid_out' => $f($paidPeriod),
            'paid_out_total' => $f($paidTotal),
            'counts' => [
                'items' => (int) ($by['earning']['n'] ?? 0),
                'refunds' => (int) $q()->where('entry_type', 'refund_reversal')->distinct()->count('group_key'),
                'payouts_paid' => (int) (clone $paid)->whereBetween('paid_at', [$from, $to])->count(),
                'payouts_open' => (int) (clone $open)->count(),
            ],
        ];
    }

    /**
     * Per-item breakdown (one row per captured payment) for a provider.
     *
     * @return array<int, array{date: string, service: string, description: string, gross: float, commission: float, refunded: float, net: float, cash: bool, group: string}>
     */
    public function items(string $accountType, int $accountId, Carbon $from, Carbon $to, int $limit = 500): array
    {
        $earnings = LedgerEntry::query()->where('account_type', $accountType)->where('account_id', $accountId)
            ->whereIn('entry_type', $accountType === 'platform' ? ['earning', 'commission', 'fee'] : ['earning'])
            ->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        if ($earnings->isEmpty()) {
            return [];
        }
        $groups = $earnings->pluck('group_key')->filter()->unique()->values();
        $related = LedgerEntry::query()->where('account_type', $accountType)->where('account_id', $accountId)
            ->whereIn('group_key', $groups)->get()->groupBy('group_key');

        $out = [];
        $seen = [];
        foreach ($earnings as $e) {
            $g = $e->group_key ?: 'id:'.$e->id;
            if (isset($seen[$g])) {
                continue;
            }
            $seen[$g] = true;
            $rows = $related[$e->group_key] ?? collect([$e]);
            $sum = fn (string $type) => $rows->where('entry_type', $type)->sum(fn ($r) => Money::cents((string) $r->amount));
            if ($accountType === 'platform') {
                $gross = $sum('earning') + $sum('commission') + $sum('fee');
                $commission = $sum('commission');
                $net = $gross;
            } else {
                $gross = $sum('earning');
                $commission = -$sum('commission');
                $net = $gross - $commission;
            }
            $refunded = $sum('refund_reversal');
            $out[] = [
                'date' => $e->created_at?->toIso8601String(),
                'service' => (string) $e->service_type,
                'description' => (string) $e->description,
                'gross' => Money::toFloat($gross),
                'commission' => Money::toFloat($commission),
                'refunded' => Money::toFloat($refunded),
                'net' => Money::toFloat($net + $refunded),
                'cash' => $rows->contains(fn ($r) => $r->entry_type === 'cash_collected'),
                'group' => (string) $e->group_key,
            ];
        }

        return $out;
    }

    /**
     * Totals bucketed by day / week / month.
     *
     * @return array<int, array{period: string, gross: float, commission: float, refunds: float, net: float, items: int}>
     */
    public function series(string $accountType, int $accountId, Carbon $from, Carbon $to, string $bucket = 'day'): array
    {
        $rows = LedgerEntry::query()->where('account_type', $accountType)->where('account_id', $accountId)
            ->whereBetween('created_at', [$from, $to])->whereIn('entry_type', ['earning', 'commission', 'fee', 'refund_reversal', 'adjustment'])
            ->orderBy('created_at')->get(['entry_type', 'amount', 'created_at']);
        $out = [];
        foreach ($rows as $r) {
            $d = $r->created_at->copy()->timezone('Africa/Johannesburg');
            $key = match ($bucket) {
                'week' => $d->startOfWeek()->toDateString(),
                'month' => $d->format('Y-m'),
                default => $d->toDateString(),
            };
            $out[$key] ??= ['period' => $key, 'gross' => 0, 'commission' => 0, 'refunds' => 0, 'net' => 0, 'items' => 0];
            $cents = Money::cents((string) $r->amount);
            $isPlatform = $accountType === 'platform';
            match ($r->entry_type) {
                'earning' => [$out[$key]['gross'] += $cents, $out[$key]['items']++],
                'commission' => $isPlatform ? [$out[$key]['gross'] += $cents, $out[$key]['commission'] += $cents] : $out[$key]['commission'] -= $cents,
                'fee' => $isPlatform ? $out[$key]['gross'] += $cents : null,
                'refund_reversal' => $out[$key]['refunds'] += $cents,
                default => null,
            };
            $out[$key]['net'] += $cents;
        }

        return array_values(array_map(fn ($b) => [
            'period' => $b['period'], 'gross' => Money::toFloat($b['gross']), 'commission' => Money::toFloat($b['commission']),
            'refunds' => Money::toFloat($b['refunds']), 'net' => Money::toFloat($b['net']), 'items' => $b['items'],
        ], $out));
    }

    /**
     * Platform revenue (commission + patient fees + unattributed revenue, net of refunds) by service type.
     *
     * @return array<string, array{commission: float, fees: float, other: float, refunds: float, total: float}>
     */
    public function platformRevenue(Carbon $from, Carbon $to): array
    {
        $rows = LedgerEntry::query()->where('account_type', 'platform')->whereBetween('created_at', [$from, $to])
            ->whereIn('entry_type', ['commission', 'fee', 'earning', 'refund_reversal', 'adjustment'])
            ->select('service_type', 'entry_type', DB::raw('SUM(amount) as total'))->groupBy('service_type', 'entry_type')->get();
        $out = [];
        foreach ($rows as $r) {
            $svc = $r->service_type ?: 'other';
            $out[$svc] ??= ['commission' => 0, 'fees' => 0, 'other' => 0, 'refunds' => 0, 'total' => 0];
            $cents = Money::cents((string) $r->total);
            match ($r->entry_type) {
                'commission' => $out[$svc]['commission'] += $cents,
                'fee' => $out[$svc]['fees'] += $cents,
                'refund_reversal' => $out[$svc]['refunds'] += $cents,
                default => $out[$svc]['other'] += $cents,
            };
            $out[$svc]['total'] += $cents;
        }
        ksort($out);

        return array_map(fn ($v) => array_map(fn ($c) => Money::toFloat($c), $v), $out);
    }

    /** Resolve a period keyword to [from, to] (Africa/Johannesburg calendar). */
    public static function period(?string $period, ?string $from = null, ?string $to = null): array
    {
        $tz = 'Africa/Johannesburg';
        $now = Carbon::now($tz);
        [$a, $b] = match ($period) {
            'today', 'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'custom' => [
                $from ? Carbon::parse($from, $tz)->startOfDay() : $now->copy()->startOfMonth(),
                $to ? Carbon::parse($to, $tz)->endOfDay() : $now->copy()->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()], // month
        };

        return [$a->utc(), $b->utc()];
    }

    /** Payout history for an account. */
    public function payoutHistory(string $accountType, int $accountId, int $limit = 50): array
    {
        return Payout::query()->where('account_type', $accountType)->where('account_id', $accountId)
            ->latest('id')->limit($limit)->get()
            ->map(fn (Payout $p) => [
                'id' => $p->id, 'reference' => $p->reference, 'amount' => Money::toFloat(Money::cents((string) $p->amount)),
                'status' => $p->status, 'period_start' => $p->period_start?->toDateString(), 'period_end' => $p->period_end?->toDateString(),
                'paid_at' => $p->paid_at?->toIso8601String(), 'bank_reference' => $p->bank_reference, 'failed_reason' => $p->failed_reason,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->all();
    }

    /** Outstanding provider balances (largest first). */
    public function outstandingBalances(int $limit = 100): array
    {
        return \App\Models\LedgerBalance::query()->whereIn('account_type', Accounts::PROVIDER_TYPES)->where('balance', '!=', 0)
            ->orderByDesc('balance')->limit($limit)->get()
            ->map(fn ($b) => ['account_type' => $b->account_type, 'account_id' => (int) $b->account_id, 'label' => $b->accountLabel(),
                'balance' => Money::toFloat(Money::cents((string) $b->balance))] + $this->payouts->availability($b->account_type, (int) $b->account_id))
            ->map(function ($r) {
                $r['on_hold'] = Money::toFloat($r['on_hold']);
                $r['available'] = Money::toFloat($r['available']);
                $r['balance'] = is_int($r['balance']) ? Money::toFloat($r['balance']) : $r['balance'];

                return $r;
            })->all();
    }

    public static function rangeLabel(CarbonInterface $from, CarbonInterface $to): string
    {
        return $from->copy()->timezone('Africa/Johannesburg')->format('d M Y').' – '.$to->copy()->timezone('Africa/Johannesburg')->format('d M Y');
    }
}
