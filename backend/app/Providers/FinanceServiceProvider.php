<?php

namespace App\Providers;

use App\Events\PaymentCaptured;
use App\Events\PaymentRefunded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RideCharge;
use App\Services\Finance\LedgerPoster;
use App\Services\Finance\PayoutService;
use App\Support\Tick;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Finance wiring: ledger postings on payment events (and on payments that
 * bypass payment intents), plus the weekly payout batch Tick job.
 * Every hook is fail-safe: a ledger problem is logged, never thrown into the
 * payment / consult / ride flow that triggered it.
 */
class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\Finance\LedgerService::class);
        $this->app->singleton(\App\Services\Finance\CommissionResolver::class);
    }

    public function boot(): void
    {
        $safe = function (string $what, callable $fn) {
            try {
                if (! static::ready()) {
                    return;
                }
                $fn();
            } catch (\Throwable $e) {
                Log::error('[Finance] '.$what.' failed: '.$e->getMessage());
            }
        };

        Event::listen(PaymentCaptured::class, function (PaymentCaptured $e) use ($safe) {
            $safe('capture posting', fn () => app(LedgerPoster::class)->capturedIntent($e->intent, $e->amount));
        });
        Event::listen(PaymentRefunded::class, function (PaymentRefunded $e) use ($safe) {
            $safe('refund posting', fn () => app(LedgerPoster::class)->refundedIntent($e->intent, null, $e->reason));
        });

        Payment::created(function (Payment $p) use ($safe) {
            if (in_array($p->status, ['success', 'refunded'], true)) {
                $safe('legacy payment posting', fn () => app(LedgerPoster::class)->legacyPayment($p));
            }
        });
        Payment::updated(function (Payment $p) use ($safe) {
            if ($p->wasChanged('status') && in_array($p->status, ['success', 'refunded'], true)) {
                $safe('legacy payment posting', fn () => app(LedgerPoster::class)->legacyPayment($p));
            }
        });
        Order::updated(function (Order $o) use ($safe) {
            if ($o->wasChanged('payment_status') && in_array($o->payment_status, ['paid', 'refunded'], true)) {
                $safe('order posting', fn () => app(LedgerPoster::class)->legacyOrder($o));
            }
        });
        RideCharge::updated(function (RideCharge $c) use ($safe) {
            if ($c->wasChanged('status') && in_array($c->status, [RideCharge::STATUS_PAID, RideCharge::STATUS_REFUNDED], true)) {
                $safe('ride charge posting', fn () => app(LedgerPoster::class)->legacyRideCharge($c));
            }
        });

        Tick::register('finance.payout_batch', 3600, function () use ($safe) {
            $safe('weekly payout batch', fn () => app(PayoutService::class)->autoGenerateIfDue());
        });
    }

    /** Tables exist (deploy migrates after the code lands; never break requests in between). */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = Schema::hasTable('ledger_entries') && Schema::hasTable('ledger_balances') && Schema::hasTable('commission_rules');
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok;
    }
}
