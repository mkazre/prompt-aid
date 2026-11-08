<?php

namespace App\Services\Finance;

use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of ledger_entries. Lines are posted atomically; each has a
 * unique idempotency_key, so re-delivered events / re-run backfills never
 * double-post (existing keys are skipped). balance_after is computed under
 * a row lock on ledger_balances for the account.
 *
 * Sign convention: + = the platform owes the account (provider earnings,
 * platform revenue); − = the account owes / was paid (commission, payouts,
 * cash it already collected, refunds).
 */
class LedgerService
{
    /**
     * @param  array<int, array{account_type: string, account_id?: int, entry_type: string, amount_cents: int, idempotency_key: string,
     *     source_type?: ?string, source_id?: ?int, service_type?: ?string, component?: ?string, group_key?: ?string,
     *     meta?: ?array, description?: ?string, payout_id?: ?int, created_by?: ?int, created_at?: ?CarbonInterface}>  $lines
     * @return int number of lines written (0 = all were already posted)
     */
    public function post(array $lines): int
    {
        $lines = array_values(array_filter($lines, fn ($l) => (int) $l['amount_cents'] !== 0 || ! empty($l['allow_zero'])));
        if (! $lines) {
            return 0;
        }

        try {
            return DB::transaction(function () use ($lines) {
                $written = 0;
                $keys = array_column($lines, 'idempotency_key');
                $existing = LedgerEntry::query()->whereIn('idempotency_key', $keys)->pluck('idempotency_key')->flip();
                foreach ($lines as $l) {
                    if (isset($existing[$l['idempotency_key']])) {
                        continue;
                    }
                    $type = $l['account_type'];
                    $id = (int) ($l['account_id'] ?? 0);
                    $bal = $this->lockBalance($type, $id);
                    $new = Money::cents((string) $bal->balance) + (int) $l['amount_cents'];
                    LedgerEntry::query()->create([
                        'account_type' => $type,
                        'account_id' => $id,
                        'source_type' => $l['source_type'] ?? null,
                        'source_id' => $l['source_id'] ?? null,
                        'service_type' => $l['service_type'] ?? null,
                        'entry_type' => $l['entry_type'],
                        'component' => $l['component'] ?? null,
                        'group_key' => $l['group_key'] ?? null,
                        'amount' => Money::toDecimal((int) $l['amount_cents']),
                        'currency' => 'ZAR',
                        'balance_after' => Money::toDecimal($new),
                        'payout_id' => $l['payout_id'] ?? null,
                        'meta' => $l['meta'] ?? null,
                        'description' => isset($l['description']) ? mb_substr((string) $l['description'], 0, 255) : null,
                        'idempotency_key' => mb_substr($l['idempotency_key'], 0, 191),
                        'created_by' => $l['created_by'] ?? null,
                        'created_at' => $l['created_at'] ?? now(),
                    ]);
                    $bal->forceFill(['balance' => Money::toDecimal($new)])->save();
                    $written++;
                }

                return $written;
            });
        } catch (QueryException $e) {
            // A concurrent delivery won the race on the unique key: nothing to do.
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return 0;
            }
            throw $e;
        }
    }

    protected function lockBalance(string $type, int $id): LedgerBalance
    {
        $row = LedgerBalance::query()->where('account_type', $type)->where('account_id', $id)->lockForUpdate()->first();
        if ($row) {
            return $row;
        }
        try {
            LedgerBalance::query()->create(['account_type' => $type, 'account_id' => $id, 'balance' => '0.00']);
        } catch (QueryException) {
            // created concurrently
        }

        return LedgerBalance::query()->where('account_type', $type)->where('account_id', $id)->lockForUpdate()->firstOrFail();
    }

    public function balanceCents(string $type, int $id): int
    {
        return Money::cents((string) (LedgerBalance::query()->where('account_type', $type)->where('account_id', $id)->value('balance') ?? '0'));
    }

    public function exists(string $idempotencyKey): bool
    {
        return LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    /** Sum (cents) of a query's `amount` column without float drift. */
    public static function sumCents($query): int
    {
        return Money::cents((string) ($query->sum('amount') ?? '0'));
    }

    /**
     * Manual adjustment by an admin (+ credit the account, − debit it). Reason is
     * required; the line and an audit_logs row both record who did it.
     */
    public function adjust(string $type, int $id, int $cents, string $reason, ?\App\Models\User $by, ?string $serviceType = null): LedgerEntry
    {
        $reason = trim($reason);
        if ($cents === 0 || $reason === '') {
            throw new \RuntimeException('An adjustment needs a non-zero amount and a reason.');
        }
        if (! array_key_exists($type, Accounts::TYPES)) {
            throw new \RuntimeException('Unknown account type.');
        }
        $key = 'adj:'.\Illuminate\Support\Str::uuid();
        $this->post([[
            'account_type' => $type, 'account_id' => $type === 'platform' ? 0 : $id, 'entry_type' => 'adjustment', 'component' => 'adjustment',
            'amount_cents' => $cents, 'source_type' => 'manual', 'service_type' => $serviceType, 'idempotency_key' => $key,
            'description' => 'Adjustment: '.mb_substr($reason, 0, 200), 'meta' => ['reason' => mb_substr($reason, 0, 500), 'by' => $by?->id, 'by_name' => $by?->name],
            'created_by' => $by?->id,
        ]]);
        $entry = LedgerEntry::query()->where('idempotency_key', $key)->firstOrFail();
        try {
            \App\Models\AuditLog::query()->create([
                'user_id' => $by?->id, 'event' => 'finance.adjustment', 'auditable_type' => LedgerEntry::class, 'auditable_id' => $entry->id,
                'old_values' => null, 'new_values' => ['account' => $type.':'.$id, 'amount' => Money::toDecimal($cents), 'reason' => $reason], 'ip' => request()?->ip(),
            ]);
        } catch (\Throwable) {
        }

        return $entry;
    }

    /** Recompute an account's balance from its entries (repair tool; returns the corrected balance). */
    public function rebuildBalance(string $type, int $id): int
    {
        return DB::transaction(function () use ($type, $id) {
            $bal = $this->lockBalance($type, $id);
            $running = 0;
            LedgerEntry::query()->where('account_type', $type)->where('account_id', $id)->orderBy('id')
                ->chunkById(500, function ($rows) use (&$running) {
                    foreach ($rows as $r) {
                        $running += Money::cents((string) $r->amount);
                        if (Money::cents((string) $r->balance_after) !== $running) {
                            $r->forceFill(['balance_after' => Money::toDecimal($running)])->saveQuietly();
                        }
                    }
                });
            $bal->forceFill(['balance' => Money::toDecimal($running)])->save();

            return $running;
        });
    }
}
