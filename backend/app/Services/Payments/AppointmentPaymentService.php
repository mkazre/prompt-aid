<?php

namespace App\Services\Payments;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentIntent;
use App\Support\PaymentSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Booked (scheduled) appointments: pay at booking — in full, a deposit, or
 * at the practice. Policy = doctor override → clinic override → global
 * setting (default "at_practice", so nothing changes until an admin opts in).
 * Cancellation: ≥ N hours before → full refund; later → keep a % of what was paid.
 */
class AppointmentPaymentService
{
    public const ITEM_PREFIX = 'Booking';

    public function __construct(protected PaymentService $payments) {}

    /** @return array{policy: string, deposit_percent: float} */
    public function policyFor(Appointment $appointment): array
    {
        $doctor = $appointment->doctor;
        $clinic = $appointment->clinic;
        $policy = $doctor?->payment_policy ?: ($clinic?->payment_policy ?: PaymentSettings::str('appointment_payment_policy'));
        $policy = array_key_exists($policy, PaymentSettings::POLICIES) ? $policy : 'at_practice';
        $pct = $doctor?->deposit_percent ?? $clinic?->deposit_percent ?? PaymentSettings::float('appointment_deposit_percent');

        return ['policy' => $policy, 'deposit_percent' => max(1, min(100, (float) $pct))];
    }

    public function amountFor(Appointment $appointment): float
    {
        $fee = (float) ($appointment->doctor?->consultation_fee ?? 0);
        $p = $this->policyFor($appointment);

        return match ($p['policy']) {
            'full' => round($fee, 2),
            'deposit' => round($fee * $p['deposit_percent'] / 100, 2),
            default => 0.0,
        };
    }

    /** New booking → raise the booking invoice the patient pays in the payment sheet. */
    public function onBooked(Appointment $appointment): ?Invoice
    {
        if ($appointment->status === Appointment::STATUS_MATCHING || ! $appointment->doctor_profile_id) {
            return null; // on-demand consults are handled by ConsultPaymentService
        }
        $amount = $this->amountFor($appointment);
        if ($amount <= 0 || $this->bookingInvoice($appointment)) {
            return null;
        }
        $p = $this->policyFor($appointment);
        $invoice = Invoice::query()->create([
            'appointment_id' => $appointment->id,
            'patient_profile_id' => $appointment->patient_profile_id,
            'clinic_id' => $appointment->clinic_id,
            'subtotal' => $amount, 'tax' => 0, 'discount' => 0, 'total' => $amount,
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => now()->toDateString(),
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'description' => $p['policy'] === 'deposit'
                ? self::ITEM_PREFIX.' deposit ('.rtrim(rtrim(number_format($p['deposit_percent'], 2), '0'), '.').'% of the consultation fee)'
                : self::ITEM_PREFIX.' — consultation fee paid upfront',
            'qty' => 1, 'unit_price' => $amount, 'amount' => $amount,
        ]);
        // Booking stays "pending payment"; the slot is released if unpaid after the payment window.
        $appointment->forceFill(['payment_state' => 'awaiting'])->saveQuietly();

        return $invoice;
    }

    /** Booking invoice paid → appointment no longer pending payment. */
    public function markBookingPaid(Invoice $invoice): void
    {
        $appointment = $invoice->appointment;
        if ($appointment && $appointment->payment_state === 'awaiting' && $this->bookingInvoice($appointment)?->id === $invoice->id) {
            $appointment->forceFill(['payment_state' => 'paid'])->saveQuietly();
        }
    }

    /** Payment block for the appointment API / pages. */
    public function payload(Appointment $appointment): ?array
    {
        if (! $appointment->payment_state) {
            return null;
        }
        $invoice = $this->bookingInvoice($appointment);

        return [
            'state' => $appointment->payment_state,
            'required' => $appointment->payment_state === 'awaiting' && $invoice && $invoice->status !== Invoice::STATUS_PAID,
            'payable_type' => 'invoice',
            'payable_id' => $invoice?->id,
            'amount' => $invoice ? (float) $invoice->total : null,
            'invoice_no' => $invoice?->invoice_no,
            'pay_by' => $appointment->payment_state === 'awaiting' ? $appointment->created_at?->copy()->addMinutes(max(5, PaymentSettings::int('appointment_payment_window_minutes')))->toIso8601String() : null,
        ];
    }

    /** Unpaid bookings release their slot after the payment window. */
    public function expireUnpaid(): int
    {
        $n = 0;
        Appointment::query()->where('payment_state', 'awaiting')
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->where('created_at', '<', now()->subMinutes(max(5, PaymentSettings::int('appointment_payment_window_minutes'))))
            ->limit(100)->get()
            ->each(function (Appointment $a) use (&$n) {
                $a->forceFill(['payment_state' => 'expired'])->saveQuietly();
                $a->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => 'Booking payment not completed in time — the slot was released']);
                $n++;
            });

        return $n;
    }

    public function bookingInvoice(Appointment $appointment): ?Invoice
    {
        return Invoice::query()->where('appointment_id', $appointment->id)
            ->whereHas('items', fn ($q) => $q->where('description', 'like', self::ITEM_PREFIX.'%'))
            ->latest('id')->first();
    }

    /** Patient/practice cancelled → refund per the rules (or drop the unpaid booking invoice). */
    public function onCancelled(Appointment $appointment, bool $byPractice = false): void
    {
        $invoice = $this->bookingInvoice($appointment);
        if (! $invoice) {
            return;
        }
        $intent = PaymentIntent::query()->where('payable_type', $invoice->getMorphClass())->where('payable_id', $invoice->id)
            ->where('status', PaymentIntent::STATUS_PAID)->latest('id')->first();

        if (! $intent) {
            if ($invoice->status === Invoice::STATUS_UNPAID && ! $invoice->payments()->where('status', 'success')->exists()) {
                $invoice->items()->delete();
                $invoice->delete();
            }

            return;
        }

        $refundable = round((float) $intent->amount - (float) $intent->refunded_amount, 2);
        $start = $this->startsAt($appointment);
        $free = $byPractice || ! $start || now()->diffInHours($start, false) >= PaymentSettings::int('appointment_free_cancel_hours');
        $keepPct = $free ? 0 : max(0, min(100, PaymentSettings::float('appointment_late_cancel_keep_percent')));
        $refund = round($refundable * (100 - $keepPct) / 100, 2);
        if ($refund <= 0) {
            return;
        }
        $res = $this->payments->refund($intent, $refund, $free ? 'Appointment cancelled in time' : 'Late cancellation — '.$keepPct.'% kept');
        if (! $res['success']) {
            Log::warning('[AppointmentPayment] refund failed: '.$res['message'], ['appointment' => $appointment->id]);
        }
    }

    public function startsAt(Appointment $appointment): ?Carbon
    {
        try {
            $date = $appointment->date instanceof \DateTimeInterface ? $appointment->date->format('Y-m-d') : (string) $appointment->date;

            return Carbon::parse($date.' '.$appointment->start_time, Appointment::CLINIC_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }
}
