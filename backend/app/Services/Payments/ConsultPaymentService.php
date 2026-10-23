<?php

namespace App\Services\Payments;

use App\Models\Appointment;
use App\Models\DoctorMatchRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Services\Claims\ClaimService;
use App\Services\Doctors\DoctorMatchService;
use App\Support\DoctorNowSettings;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\Log;

/**
 * "Pay before we connect you" for on-demand consults (orange triage /
 * see-a-doctor-now: video, call back, in person).
 *
 *   start()   → prepare(): payment_state = awaiting (request is NOT broadcast)
 *   webhook   → hold authorised → secured(): payment_state = secured, broadcast
 *   ConsultStarted               → capture the hold, record it on the invoice
 *   no doctor / expired / doctor no-show / doctor cancels → release (full refund)
 *   patient cancels after accept → capture the cancellation fee only, refund the rest
 *
 * Medical aid: "bill my medical aid" → only the configured co-payment is taken
 * upfront; on capture a claim is raised for the invoice via ClaimService.
 * Test mode (no gateway live): the hold is simulated instantly, nothing changes for users.
 */
class ConsultPaymentService
{
    public const NOT_REQUIRED = 'not_required';

    public const AWAITING = 'awaiting';

    public const SECURED = 'secured';

    protected bool $preparing = false;

    public function __construct(protected PaymentService $payments) {}

    public function required(): bool
    {
        return PaymentSettings::bool('consult_upfront_payment');
    }

    public function amountDue(DoctorMatchRequest $req): float
    {
        if ($req->bill_medical_aid) {
            return round(min((float) $req->price, max(0, PaymentSettings::float('consult_medical_aid_copay'))), 2);
        }

        return round((float) $req->price, 2);
    }

    /** Called right after the request row is created, before any doctor is alerted. */
    public function prepare(DoctorMatchRequest $req, bool $billMedicalAid = false): void
    {
        $billMedicalAid = $billMedicalAid && PaymentSettings::bool('consult_medical_aid_enabled')
            && $req->patient?->schemeMemberships()->exists();
        $req->forceFill(['bill_medical_aid' => $billMedicalAid])->save();

        $gm = $this->payments->gateways();
        if (! $this->required() || $this->amountDue($req) <= 0 || (! $gm->mockActive() && ! $gm->onlineActive())) {
            $req->forceFill(['payment_state' => self::NOT_REQUIRED])->save();

            return;
        }

        $req->forceFill([
            'payment_state' => self::AWAITING,
            'expires_at' => now()->addMinutes(max(2, PaymentSettings::int('consult_payment_window_minutes'))),
        ])->save();

        if ($gm->mockActive()) {
            // Test mode: simulate the upfront hold so the flow is identical to live.
            $this->preparing = true;
            try {
                $user = $req->patient?->user;
                if ($user) {
                    [$intent] = $this->payments->start(new PayableIntent(
                        user: $user, payable: $req, service: 'invoice', amount: $this->amountDue($req),
                        description: $this->payments->payables()->describe($req), gateway: 'mock', channel: 'server',
                    ));
                    $this->payments->completeMock($intent);
                }
            } catch (\Throwable $e) {
                Log::warning('[ConsultPayment] mock hold failed: '.$e->getMessage());
                $req->forceFill(['payment_state' => self::NOT_REQUIRED])->save();
            } finally {
                $this->preparing = false;
            }
            if ($req->fresh()->payment_state === self::AWAITING) {
                $req->forceFill(['payment_state' => self::NOT_REQUIRED])->save();
            }
        }
    }

    /** Hold authorised. False → the request no longer wants it (caller releases). */
    public function secured(DoctorMatchRequest $req, PaymentIntent $intent): bool
    {
        $req = $req->fresh();
        if ($req->payment_state === self::SECURED) {
            return (int) $req->payment_intent_id === (int) $intent->id;
        }
        if ($req->status !== DoctorMatchRequest::SEARCHING || $req->payment_state !== self::AWAITING) {
            return false;
        }
        $req->forceFill([
            'payment_state' => self::SECURED,
            'payment_intent_id' => $intent->id,
            'expires_at' => now()->addMinutes(max(1, DoctorNowSettings::int('doctor_now_request_timeout_minutes'))),
        ])->save();

        if (! $this->preparing) {
            app(DoctorMatchService::class)->progress($req);
        }

        return true;
    }

    public function intentFor(DoctorMatchRequest $req): ?PaymentIntent
    {
        return $req->payment_intent_id ? PaymentIntent::query()->find($req->payment_intent_id) : null;
    }

    public function forAppointment(Appointment $appointment): ?DoctorMatchRequest
    {
        return DoctorMatchRequest::query()->where('appointment_id', $appointment->id)->latest('id')->first();
    }

    /** ConsultStarted → capture the full upfront amount. */
    public function captureFor(DoctorMatchRequest $req, string $reason = 'Consult started'): float
    {
        $intent = $this->intentFor($req);
        if (! $intent || $intent->status !== PaymentIntent::STATUS_AUTHORIZED) {
            return 0.0;
        }
        $captured = $this->payments->capture($intent, null, $reason);
        if ($captured > 0) {
            $this->recordOnInvoice($req->fresh(), $intent->fresh(), $captured, raiseClaim: true);
        }

        return $captured;
    }

    /** Patient cancelled after accept: keep the fee, give back the rest. */
    public function captureFee(DoctorMatchRequest $req, float $fee): float
    {
        $intent = $this->intentFor($req);
        if (! $intent || $intent->status !== PaymentIntent::STATUS_AUTHORIZED) {
            return 0.0;
        }
        if ($fee <= 0) {
            $this->payments->release($intent, 'Consult cancelled inside the free window');

            return 0.0;
        }
        $captured = $this->payments->capture($intent, $fee, 'Cancellation fee');
        if ($captured > 0) {
            $this->recordOnInvoice($req->fresh(), $intent->fresh(), $captured, raiseClaim: false);
        }

        return $captured;
    }

    public function releaseFor(DoctorMatchRequest $req, string $reason): bool
    {
        $intent = $this->intentFor($req);
        if (! $intent) {
            // An unpaid attempt may exist without being linked yet.
            PaymentIntent::query()->where('payable_type', $req->getMorphClass())->where('payable_id', $req->id)
                ->whereIn('status', [PaymentIntent::STATUS_PENDING, PaymentIntent::STATUS_PROCESSING])
                ->get()->each(fn (PaymentIntent $i) => $this->payments->release($i, $reason));

            return false;
        }

        return $this->payments->release($intent, $reason);
    }

    /** ConsultEnded outcome → capture or release. */
    public function onEnded(Appointment $appointment, string $outcome): void
    {
        $req = $this->forAppointment($appointment);
        if (! $req) {
            return;
        }
        $o = strtolower($outcome);
        if ($o === 'no_answer') {
            $this->settleMissedCallback($req, $appointment);
        } elseif (in_array($o, ['completed', 'patient_no_show', 'ended', 'done'], true)) {
            $this->captureFor($req, 'Consult ended: '.$o);
        } elseif (str_contains($o, 'doctor') || in_array($o, ['failed', 'cancelled', 'expired', 'technical_failure'], true)) {
            $this->releaseFor($req, 'Consult not delivered ('.$o.')');
        }
    }

    /**
     * Missed call-back (patient did not answer): keep the missed-call fee ONLY
     * if the doctor made at least N attempts spanning at least M minutes
     * (appointments.call_attempts, called_at = first, last_call_attempt_at = last).
     * Otherwise everything is refunded. The reason is put on the invoice and
     * sent to the patient as an in-app notice.
     *
     * @return array{fee: float, reason: string}
     */
    public function settleMissedCallback(DoctorMatchRequest $req, Appointment $appointment): array
    {
        $appointment = $appointment->fresh() ?? $appointment;
        $attempts = (int) $appointment->call_attempts;
        $first = $appointment->called_at;
        $last = $appointment->last_call_attempt_at ?? $appointment->called_at;
        $spanMin = $first && $last ? $first->diffInSeconds($last, true) / 60 : 0.0;
        $minAttempts = max(1, PaymentSettings::int('callback_missed_min_attempts'));
        $minMinutes = max(0, PaymentSettings::float('callback_missed_min_minutes'));
        $intent = $this->intentFor($req);
        $held = $intent ? (float) $intent->amount : (float) $req->price;
        $fee = round(min(max(0, PaymentSettings::float('callback_missed_fee')), (float) $req->price, $held), 2);
        $tried = 'the doctor called '.$attempts.' time'.($attempts === 1 ? '' : 's').' over '.round($spanMin, 1).' min';
        $qualifies = $attempts >= $minAttempts && $spanMin + 0.0001 >= $minMinutes && $fee > 0;

        if ($qualifies) {
            $reason = 'Missed call-back fee: '.$tried.' and the call was not answered';
            $captured = $intent && $intent->status === PaymentIntent::STATUS_AUTHORIZED ? $this->payments->capture($intent, $fee, $reason) : 0.0;
            $this->resetInvoiceTo($req, $captured > 0 ? $captured : $fee, $reason);
            if ($captured > 0) {
                $this->recordOnInvoice($req->fresh(), $intent->fresh(), $captured, raiseClaim: false);
            }
            $this->notifyPatient($req, 'Missed call-back', 'Your doctor could not reach you ('.$tried.'). A missed call-back fee of R'.number_format($fee, 2).' applies; the rest of your payment has been refunded.');

            return ['fee' => $captured > 0 ? $captured : $fee, 'reason' => $reason];
        }

        $reason = 'Call-back not answered, but the minimum call attempts were not met ('.$tried.'; needs '.$minAttempts.' over '.$minMinutes.' min) — full refund';
        $this->releaseFor($req, $reason);
        $this->resetInvoiceTo($req, 0.0, $reason);
        $this->notifyPatient($req, 'Call-back missed — full refund', 'We could not complete your call-back. You have been refunded in full.');

        return ['fee' => 0.0, 'reason' => $reason];
    }

    /** Rewrite an unpaid consult invoice to a single line (or remove it when nothing is owed). */
    protected function resetInvoiceTo(DoctorMatchRequest $req, float $amount, string $description): void
    {
        $invoice = $req->fresh()->invoice;
        if (! $invoice || $invoice->payments()->where('status', 'success')->exists()) {
            return;
        }
        $invoice->items()->delete();
        if ($amount <= 0) {
            $invoice->delete();

            return;
        }
        \App\Models\InvoiceItem::query()->create([
            'invoice_id' => $invoice->id, 'description' => mb_substr($description, 0, 255),
            'qty' => 1, 'unit_price' => $amount, 'amount' => $amount,
        ]);
        $invoice->update(['subtotal' => $amount, 'total' => $amount]);
    }

    protected function notifyPatient(DoctorMatchRequest $req, string $title, string $body): void
    {
        try {
            $user = $req->patient?->user;
            if ($user) {
                app(\App\Services\Notifications\InAppNotifier::class)->send($user, 'appointment', $title, $body, [
                    'route' => 'appointment', 'params' => ['appointment_id' => $req->appointment_id], 'url' => '/account/invoices',
                ]);
            }
        } catch (\Throwable $e) {
            Log::info('[ConsultPayment] notify failed: '.$e->getMessage());
        }
    }

    protected function recordOnInvoice(DoctorMatchRequest $req, PaymentIntent $intent, float $amount, bool $raiseClaim): void
    {
        $invoice = $req->invoice;
        if (! $invoice) {
            return;
        }
        $ref = $intent->reference.'-CAP';
        if (! Payment::query()->where('invoice_id', $invoice->id)->where('gateway_ref', $ref)->exists()) {
            Payment::query()->create([
                'invoice_id' => $invoice->id, 'method' => 'card', 'amount' => $amount, 'status' => 'success',
                'gateway' => $intent->gateway, 'gateway_ref' => $ref,
            ]);
        }
        $paid = (float) $invoice->payments()->where('status', 'success')->sum('amount');
        $invoice->update(['status' => $paid + 0.009 >= (float) $invoice->total ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID]);

        if ($raiseClaim && $req->bill_medical_aid && $paid + 0.009 < (float) $invoice->total) {
            try {
                $membership = $req->patient?->schemeMemberships()->latest('id')->first();
                if ($membership) {
                    $claim = app(ClaimService::class)->createFromInvoice($invoice, $membership);
                    $claim->update(['amount_claimed' => round((float) $invoice->total - $paid, 2)]);
                }
            } catch (\Throwable $e) {
                Log::warning('[ConsultPayment] claim creation failed: '.$e->getMessage());
            }
        }
    }

    /** Extra block for DoctorMatchService::patientPayload(). */
    public function payload(DoctorMatchRequest $req): array
    {
        $intent = $this->intentFor($req);

        return [
            'required' => $req->payment_state === self::AWAITING,
            'state' => $req->payment_state ?? self::NOT_REQUIRED,
            'amount' => $this->amountDue($req),
            'price' => (float) $req->price,
            'bill_medical_aid' => (bool) $req->bill_medical_aid,
            'payable_type' => 'consult',
            'payable_id' => $req->id,
            'intent_status' => $intent?->status,
            'captured' => $intent ? (float) $intent->captured_amount : 0.0,
            'pay_by' => $req->payment_state === self::AWAITING ? $req->expires_at?->toIso8601String() : null,
        ];
    }
}
