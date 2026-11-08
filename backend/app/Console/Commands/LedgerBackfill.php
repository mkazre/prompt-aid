<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\RideCharge;
use App\Services\Finance\LedgerPoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Post ledger entries for payments captured before the ledger existed.
 * Idempotent (same idempotency keys as the live listeners), so it can be
 * re-run safely. Default is a DRY RUN: everything is posted inside a
 * transaction that is rolled back, and the would-be totals are printed.
 * Entries are dated at the original capture / refund time and use the
 * commission rule in force at that time (rules with no effective_from
 * apply to all history).
 *
 *   php artisan ledger:backfill            # dry run
 *   php artisan ledger:backfill --commit   # write
 */
class LedgerBackfill extends Command
{
    protected $signature = 'ledger:backfill {--commit : Write the entries (default is a dry run)} {--since= : Only payments on/after this date (YYYY-MM-DD)}';

    protected $description = 'Post ledger entries for already-captured payments (idempotent; dry run unless --commit)';

    public function handle(LedgerPoster $poster): int
    {
        $commit = (bool) $this->option('commit');
        $since = $this->option('since') ? \Illuminate\Support\Carbon::parse((string) $this->option('since'))->startOfDay() : null;
        $maxBefore = (int) (LedgerEntry::query()->max('id') ?? 0);
        $stats = ['intents' => 0, 'payments' => 0, 'orders' => 0, 'ride_charges' => 0, 'lines' => 0, 'errors' => 0];

        DB::beginTransaction();
        try {
            PaymentIntent::query()
                ->whereIn('status', [PaymentIntent::STATUS_PAID, PaymentIntent::STATUS_REFUNDED])
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->orderBy('id')->chunkById(200, function ($rows) use ($poster, &$stats) {
                    foreach ($rows as $i) {
                        try {
                            $captured = $i->captured_amount !== null ? (float) $i->captured_amount : (float) $i->amount;
                            $n = $poster->capturedIntent($i, $captured, $i->captured_at ?? $i->paid_at ?? $i->created_at);
                            if ((float) $i->refunded_amount > 0) {
                                $n += $poster->refundedIntent($i, $i->refunded_at ?? $i->updated_at, 'Refund (backfill)');
                            }
                            $stats['lines'] += $n;
                            $stats['intents'] += $n > 0 ? 1 : 0;
                        } catch (\Throwable $e) {
                            $stats['errors']++;
                            $this->warn("intent {$i->id}: ".$e->getMessage());
                        }
                    }
                });

            $legacy = [
                'payments' => [Payment::query()->whereIn('status', ['success', 'refunded']), fn ($m) => $poster->legacyPayment($m)],
                'orders' => [Order::query()->whereIn('payment_status', ['paid', 'refunded']), fn ($m) => $poster->legacyOrder($m)],
                'ride_charges' => [RideCharge::query()->whereIn('status', [RideCharge::STATUS_PAID, RideCharge::STATUS_REFUNDED]), fn ($m) => $poster->legacyRideCharge($m)],
            ];
            foreach ($legacy as $key => [$query, $fn]) {
                $query->when($since, fn ($q) => $q->where('created_at', '>=', $since))->orderBy('id')
                    ->chunkById(200, function ($rows) use ($fn, $key, &$stats) {
                        foreach ($rows as $m) {
                            try {
                                $n = $fn($m);
                                $stats['lines'] += $n;
                                $stats[$key] += $n > 0 ? 1 : 0;
                            } catch (\Throwable $e) {
                                $stats['errors']++;
                                $this->warn("{$key} {$m->id}: ".$e->getMessage());
                            }
                        }
                    });
            }

            $summary = LedgerEntry::query()->where('id', '>', $maxBefore)
                ->select('account_type', DB::raw('COUNT(*) as n'), DB::raw('SUM(amount) as total'))->groupBy('account_type')->get();

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Backfill failed, nothing written: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($commit ? 'POSTED' : 'DRY RUN (nothing written — add --commit)').': '.$stats['lines'].' ledger lines');
        $this->line("  online payments: {$stats['intents']} · invoice payments (non-online): {$stats['payments']} · orders: {$stats['orders']} · ride charges: {$stats['ride_charges']} · errors: {$stats['errors']}");
        foreach ($summary as $s) {
            $this->line('  '.str_pad($s->account_type, 10).' '.str_pad((string) $s->n, 6, ' ', STR_PAD_LEFT).' lines  net R'.number_format((float) $s->total, 2));
        }

        return $stats['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
