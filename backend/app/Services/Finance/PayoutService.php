<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Support\FinanceSettings;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Weekly provider payouts.
 *
 *   available = balance − (positive earning/commission/refund lines still inside the hold period)
 * A batch takes every provider account whose available amount is at least
 * the minimum payout (and whose bank details are verified, unless that
 * setting is off), creates a pending payout and immediately posts a
 * "payout" ledger line (−amount) so the money cannot be paid twice.
 * Approve → export the bank CSV → mark each payout paid (bank reference)
 * or failed (the amount is credited back to the provider's balance).
 */
class PayoutService
{
    public function __construct(protected LedgerService $ledger) {}

    /** @return array{balance: int, on_hold: int, available: int} cents */
    public function availability(string $type, int $id, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $balance = $this->ledger->balanceCents($type, $id);
        $cutoff = $now->copy()->subDays(FinanceSettings::holdDays());
        $held = LedgerService::sumCents(LedgerEntry::query()
            ->where('account_type', $type)->where('account_id', $id)
            ->where('created_at', '>', $cutoff)->where('amount', '>', 0)
            ->whereNotIn('entry_type', ['payout', 'adjustment']));
        $onHold = max(0, min($held, $balance));

        return ['balance' => $balance, 'on_hold' => $onHold, 'available' => max(0, $balance - $onHold)];
    }

    /**
     * @return array{batch: ?PayoutBatch, skipped: array<int, string>}
     */
    public function generateBatch(?User $by = null, string $source = 'manual', ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $min = max(1, FinanceSettings::minPayoutCents());
        $needVerified = FinanceSettings::bool('finance_require_verified_bank');
        $skipped = [];

        return DB::transaction(function () use ($by, $source, $now, $min, $needVerified, &$skipped) {
            $last = PayoutBatch::query()->where('status', '!=', 'cancelled')->latest('id')->first();
            $periodEnd = $now->copy()->subDays(FinanceSettings::holdDays())->toDateString();
            $periodStart = $last?->period_end ? $last->period_end->copy()->addDay()->toDateString() : null;

            $candidates = LedgerBalance::query()->whereIn('account_type', Accounts::PROVIDER_TYPES)->where('balance', '>', 0)->orderBy('id')->get();
            $plan = [];
            foreach ($candidates as $b) {
                $a = $this->availability($b->account_type, (int) $b->account_id, $now);
                $label = Accounts::label($b->account_type, (int) $b->account_id);
                if ($a['available'] < $min) {
                    if ($a['available'] > 0) {
                        $skipped[] = $label.': '.Money::format($a['available']).' available is below the minimum payout';
                    }

                    continue;
                }
                $acct = PayoutAccount::query()->where('account_type', $b->account_type)->where('account_id', $b->account_id)->first();
                if (! $acct || ($needVerified && ! $acct->isVerified())) {
                    $skipped[] = $label.': '.(! $acct ? 'no bank details' : 'bank details not verified yet').' ('.Money::format($a['available']).' waiting)';

                    continue;
                }
                $plan[] = [$b, $a['available'], $acct];
            }
            if (! $plan) {
                return ['batch' => null, 'skipped' => $skipped];
            }

            $batch = PayoutBatch::query()->create([
                'reference' => 'PB'.$now->format('ymd').strtoupper(Str::random(4)),
                'period_start' => $periodStart, 'period_end' => $periodEnd, 'status' => 'pending', 'source' => $source,
                'created_by' => $by?->id, 'meta' => ['skipped' => array_slice($skipped, 0, 200), 'hold_days' => FinanceSettings::holdDays(), 'min_payout' => Money::toDecimal($min)],
            ]);
            $total = 0;
            foreach ($plan as [$b, $amount, $acct]) {
                $payout = Payout::query()->create([
                    'payout_batch_id' => $batch->id, 'account_type' => $b->account_type, 'account_id' => $b->account_id,
                    'payout_account_id' => $acct->id, 'amount' => Money::toDecimal($amount),
                    'period_start' => $periodStart, 'period_end' => $periodEnd, 'status' => 'pending',
                ]);
                $payout->update(['reference' => $this->beneficiaryReference($payout)]);
                $this->ledger->post([[
                    'account_type' => $b->account_type, 'account_id' => (int) $b->account_id, 'entry_type' => 'payout', 'component' => 'payout',
                    'amount_cents' => -$amount, 'source_type' => 'payout', 'source_id' => $payout->id, 'payout_id' => $payout->id,
                    'idempotency_key' => 'payout:'.$payout->id, 'description' => 'Payout '.$payout->reference.' (batch '.$batch->reference.')',
                    'created_by' => $by?->id,
                ]]);
                $total += $amount;
            }
            $batch->update(['total_amount' => Money::toDecimal($total), 'payout_count' => count($plan)]);
            $this->audit($by, 'finance.payout_batch.generated', $batch, ['total' => Money::toDecimal($total), 'count' => count($plan), 'source' => $source]);

            return ['batch' => $batch->fresh(), 'skipped' => $skipped];
        });
    }

    public function beneficiaryReference(Payout $p): string
    {
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper(FinanceSettings::str('finance_reference_prefix'))) ?: 'PROMPTAID';

        return mb_substr($prefix.' P'.$p->id, 0, 20);
    }

    public function approve(PayoutBatch $batch, ?User $by): void
    {
        if ($batch->status !== 'pending') {
            throw new RuntimeException('Only a pending batch can be approved.');
        }
        DB::transaction(function () use ($batch, $by) {
            $batch->payouts()->where('status', 'pending')->update(['status' => 'approved', 'actioned_by' => $by?->id]);
            $batch->update(['status' => 'approved', 'approved_by' => $by?->id, 'approved_at' => now()]);
        });
        $this->audit($by, 'finance.payout_batch.approved', $batch, []);
    }

    /** Cancel a batch that has not been paid: every open payout's amount goes back to the provider. */
    public function cancel(PayoutBatch $batch, ?User $by, string $reason = 'Batch cancelled'): void
    {
        if (! in_array($batch->status, ['pending', 'approved'], true)) {
            throw new RuntimeException('Only a pending or approved batch can be cancelled.');
        }
        if ($batch->payouts()->whereIn('status', ['paid'])->exists()) {
            throw new RuntimeException('Some payouts in this batch are already marked paid — mark the rest failed instead.');
        }
        DB::transaction(function () use ($batch, $by, $reason) {
            foreach ($batch->payouts()->whereIn('status', ['pending', 'approved'])->get() as $p) {
                $this->returnToBalance($p, $by, $reason, 'cancelled');
            }
            $batch->update(['status' => 'cancelled']);
        });
        $this->audit($by, 'finance.payout_batch.cancelled', $batch, ['reason' => $reason]);
    }

    public function markPaid(Payout $p, ?string $bankReference, ?User $by): void
    {
        if (! in_array($p->status, ['approved', 'pending'], true) || $p->batch?->status === 'pending') {
            throw new RuntimeException('Approve the batch first; only approved payouts can be marked paid.');
        }
        $p->update(['status' => 'paid', 'paid_at' => now(), 'bank_reference' => $bankReference ? mb_substr($bankReference, 0, 100) : null, 'actioned_by' => $by?->id]);
        $this->audit($by, 'finance.payout.paid', $p, ['bank_reference' => $bankReference]);
        $this->closeBatchIfDone($p->batch);
    }

    public function markFailed(Payout $p, string $reason, ?User $by): void
    {
        if (! in_array($p->status, ['approved', 'pending', 'paid'], true)) {
            throw new RuntimeException('This payout is already closed.');
        }
        DB::transaction(fn () => $this->returnToBalance($p, $by, $reason, 'failed'));
        $this->audit($by, 'finance.payout.failed', $p, ['reason' => $reason]);
        $this->closeBatchIfDone($p->batch);
    }

    protected function returnToBalance(Payout $p, ?User $by, string $reason, string $status): void
    {
        $this->ledger->post([[
            'account_type' => $p->account_type, 'account_id' => (int) $p->account_id, 'entry_type' => 'payout', 'component' => 'return',
            'amount_cents' => Money::cents((string) $p->amount), 'source_type' => 'payout', 'source_id' => $p->id, 'payout_id' => $p->id,
            'idempotency_key' => 'payout-return:'.$p->id, 'description' => 'Payout '.$p->reference.' '.$status.' — returned to balance: '.mb_substr($reason, 0, 150),
            'created_by' => $by?->id,
        ]]);
        $p->update(['status' => $status, 'failed_reason' => mb_substr($reason, 0, 255), 'actioned_by' => $by?->id]);
    }

    protected function closeBatchIfDone(?PayoutBatch $batch): void
    {
        if ($batch && in_array($batch->status, ['approved', 'pending'], true) && ! $batch->payouts()->whereIn('status', ['pending', 'approved'])->exists()) {
            $batch->update(['status' => 'completed']);
        }
    }

    /**
     * Standard SA bulk EFT import (one row per beneficiary):
     *   Beneficiary Name, Account Number, Branch Code, Amount, Reference
     * Amount as 1234.50 (no currency symbol / thousands separator).
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function bankCsv(PayoutBatch $batch): array
    {
        if (! in_array($batch->status, ['approved', 'completed'], true)) {
            throw new RuntimeException('Approve the batch before exporting it to the bank.');
        }
        $rows = [];
        foreach ($batch->payouts()->whereIn('status', ['approved', 'paid'])->with('payoutAccount')->orderBy('id')->get() as $p) {
            $acct = $p->payoutAccount;
            if (! $acct) {
                continue;
            }
            $rows[] = [
                $this->clean($acct->account_holder, 32),
                preg_replace('/\D/', '', (string) $acct->account_number),
                preg_replace('/\D/', '', (string) $acct->branch_code),
                Money::toDecimal(Money::cents((string) $p->amount)),
                $this->clean($p->reference ?: $this->beneficiaryReference($p), 20),
            ];
        }

        return ['headers' => ['Beneficiary Name', 'Account Number', 'Branch Code', 'Amount', 'Reference'], 'rows' => $rows];
    }

    public function bankCsvString(PayoutBatch $batch): string
    {
        $csv = $this->bankCsv($batch);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $csv['headers'], ',', '"', '');
        foreach ($csv['rows'] as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        if (! $batch->exported_at) {
            $batch->update(['exported_at' => now()]);
        }

        return $out;
    }

    protected function clean(?string $s, int $max): string
    {
        $s = preg_replace('/[^A-Za-z0-9 .\-\/&]/', '', (string) $s);

        return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, $max);
    }

    /** Tick job: one automatic batch per week on the configured weekday. */
    public function autoGenerateIfDue(): ?PayoutBatch
    {
        if (! FinanceSettings::bool('finance_auto_batch')) {
            return null;
        }
        $now = now();
        if ((int) $now->isoWeekday() !== max(1, min(7, FinanceSettings::int('finance_batch_weekday') ?: 1))) {
            return null;
        }
        if (PayoutBatch::query()->where('source', 'auto')->where('created_at', '>=', $now->copy()->startOfDay())->exists()) {
            return null;
        }

        return $this->generateBatch(null, 'auto', $now)['batch'];
    }

    public function audit(?User $by, string $event, $model, array $values): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => $by?->id, 'event' => $event, 'auditable_type' => $model ? get_class($model) : null, 'auditable_id' => $model?->id,
                'old_values' => null, 'new_values' => $values, 'ip' => request()?->ip(),
            ]);
        } catch (\Throwable) {
            // auditing never blocks money movement
        }
    }
}
