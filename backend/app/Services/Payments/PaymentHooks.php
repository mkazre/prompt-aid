<?php

namespace App\Services\Payments;

use App\Events\ConsultEnded;
use App\Events\ConsultStarted;
use App\Events\RideCancelled;
use App\Events\RideCompleted;
use App\Models\Appointment;
use App\Models\Claim;
use App\Models\DoctorMatchRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Ride;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Wires upfront payments into the rest of the platform (registered from
 * AppServiceProvider::boot). Every hook is fail-safe: a payment problem is
 * logged, never allowed to break a consult, booking, ride or order.
 */
class PaymentHooks
{
    protected static ?bool $ready = null;

    public static function register(): void
    {
        // --- Consults (events fired by the consult hub) ---
        Event::listen(ConsultStarted::class, function (ConsultStarted $e) {
            self::safe(function () use ($e) {
                $req = app(ConsultPaymentService::class)->forAppointment($e->appointment);
                if ($req) {
                    app(ConsultPaymentService::class)->captureFor($req, 'Consult started ('.$e->channel.')');
                }
            });
        });
        Event::listen(ConsultEnded::class, fn (ConsultEnded $e) => self::safe(fn () => app(ConsultPaymentService::class)->onEnded($e->appointment, $e->outcome)));

        // --- Shuttle ---
        Event::listen(RideCancelled::class, fn (RideCancelled $e) => self::safe(fn () => app(RidePaymentService::class)->release($e->ride, 'Trip cancelled'.($e->fee > 0 ? ' (fee captured separately)' : ''))));
        Event::listen(RideCompleted::class, fn (RideCompleted $e) => self::safe(fn () => app(RidePaymentService::class)->release($e->ride, 'Trip completed — nothing further to capture')));

        Ride::created(function (Ride $ride) {
            if (self::ready()) {
                self::safe(fn () => app(RidePaymentService::class)->autoHoldInTestMode($ride));
            }
        });

        // --- Appointments: booking payment + refunds; consult capture fallback ---
        Appointment::created(function (Appointment $a) {
            if (self::ready()) {
                self::safe(fn () => app(AppointmentPaymentService::class)->onBooked($a));
            }
        });
        Appointment::updated(function (Appointment $a) {
            if (! self::ready() || ! $a->wasChanged('status')) {
                return;
            }
            if ($a->status === Appointment::STATUS_CANCELLED) {
                self::safe(fn () => app(AppointmentPaymentService::class)->onCancelled($a));
            } elseif ($a->status === Appointment::STATUS_COMPLETED) {
                // Safety net if ConsultStarted never fired: a completed consult is captured.
                self::safe(function () use ($a) {
                    $req = app(ConsultPaymentService::class)->forAppointment($a);
                    if ($req) {
                        app(ConsultPaymentService::class)->captureFor($req, 'Consult completed');
                    }
                });
            }
        });

        // --- Payment windows: unpaid ride requests / bookings lapse (cron-optional tick) ---
        \App\Support\Tick::register('payments.expire_unpaid', 60, function () {
            if (self::ready()) {
                self::safe(fn () => app(RidePaymentService::class)->expireUnpaid());
                self::safe(fn () => app(AppointmentPaymentService::class)->expireUnpaid());
            }
        });

        // --- Consult cancelled by the practice while still searching → release the hold ---
        DoctorMatchRequest::updated(function (DoctorMatchRequest $r) {
            if (self::ready() && $r->wasChanged('status') && in_array($r->status, [DoctorMatchRequest::CANCELLED, DoctorMatchRequest::EXPIRED], true)
                && $r->getOriginal('status') === DoctorMatchRequest::SEARCHING) {
                self::safe(fn () => app(ConsultPaymentService::class)->releaseFor($r, 'Request closed before a doctor accepted'));
            }
        });

        // --- Medical aid: scheme outcome → credit the scheme payment, co-pay already on the invoice ---
        Claim::updated(function (Claim $c) {
            if (self::ready() && $c->wasChanged(['status', 'amount_paid'])
                && in_array($c->status, [Claim::STATUS_ACCEPTED, Claim::STATUS_PART_PAID, Claim::STATUS_REJECTED], true)) {
                self::safe(fn () => self::settleClaim($c));
            }
        });

        // --- Pharmacy: paid order cancelled → refund ---
        Order::updated(function (Order $o) {
            if (! self::ready() || ! $o->wasChanged('status') || $o->status !== Order::STATUS_CANCELLED || ! PaymentSettings::bool('order_auto_refund_on_cancel')) {
                return;
            }
            self::safe(function () use ($o) {
                $intent = PaymentIntent::query()->where('payable_type', $o->getMorphClass())->where('payable_id', $o->id)
                    ->where('status', PaymentIntent::STATUS_PAID)->latest('id')->first();
                if ($intent) {
                    app(PaymentService::class)->refund($intent, null, 'Order cancelled');
                }
            });
        });
    }

    /**
     * Scheme claim settled / rejected. The co-pay taken upfront is already a
     * successful Payment on the invoice, so it is automatically credited:
     *   outstanding = invoice total − (co-pay + scheme payment).
     *  - scheme paid the rest in full → invoice paid; the co-pay is the patient's portion (nothing refunded)
     *  - part-paid / rejected → invoice partially paid; the patient sees the shortfall (minus the co-pay) and pays it with "Pay now"
     *  - scheme paid more than needed → the overlap is refunded from the co-pay
     */
    public static function settleClaim(Claim $claim): void
    {
        $invoice = $claim->claimable;
        if (! $invoice instanceof Invoice) {
            return;
        }
        $schemePaid = round((float) ($claim->amount_paid ?? 0), 2);
        $ref = 'CLAIM-'.$claim->id;
        $row = Payment::query()->where('invoice_id', $invoice->id)->where('gateway_ref', $ref)->first();
        if ($schemePaid > 0) {
            $row ? $row->update(['amount' => $schemePaid, 'status' => 'success'])
                : Payment::query()->create(['invoice_id' => $invoice->id, 'method' => 'insurance', 'amount' => $schemePaid, 'status' => 'success', 'gateway' => 'scheme', 'gateway_ref' => $ref]);
        } elseif ($row) {
            $row->delete();
        }

        $paid = round((float) $invoice->payments()->where('status', 'success')->sum('amount'), 2);
        $total = (float) $invoice->total;
        $over = round($paid - $total, 2);
        if ($over > 0) {
            $intent = PaymentIntent::query()->whereIn('status', [PaymentIntent::STATUS_PAID])
                ->whereHasMorph('payable', [DoctorMatchRequest::class], fn ($q) => $q->where('invoice_id', $invoice->id))
                ->latest('id')->first();
            if ($intent) {
                app(PaymentService::class)->refund($intent, $over, 'Medical aid paid in full — co-payment returned');
            }
        }
        $invoice->update(['status' => $paid + 0.009 >= $total ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID]);

        $user = $invoice->patient?->user;
        if ($user) {
            $owed = max(0, round($total - $paid, 2));
            app(\App\Services\Notifications\InAppNotifier::class)->send($user, 'appointment',
                $owed > 0 ? 'Medical aid shortfall' : 'Medical aid paid',
                $owed > 0
                    ? 'Your scheme paid R'.number_format($schemePaid, 2).' on '.$invoice->invoice_no.'. Your co-payment is already credited; R'.number_format($owed, 2).' is still outstanding.'
                    : 'Your scheme settled '.$invoice->invoice_no.'. Nothing further is owed.',
                ['url' => '/account/invoices']);
        }
    }

    protected static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('payment_intents') && Schema::hasColumn('payment_intents', 'capture_mode');
    }

    protected static function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('[Payments] hook failed: '.$e->getMessage());
        }
    }
}
