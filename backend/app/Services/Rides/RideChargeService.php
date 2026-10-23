<?php

namespace App\Services\Rides;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Ride;
use App\Models\RideCharge;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Charges ride fares / cancellation fees / no-show fees through
 * PaymentGatewayInterface (mock today; swap the binding for a real gateway)
 * and records each as a RideCharge. Admin can waive (unpaid) or refund
 * (paid) from the Filament ride view.
 */
class RideChargeService
{
    public function __construct(protected PaymentGatewayInterface $gateway) {}

    public function charge(Ride $ride, string $type, float $amount, ?string $note = null, string $method = 'card'): ?RideCharge
    {
        if ($amount <= 0) {
            return null;
        }

        $charge = RideCharge::query()->create([
            'ride_id' => $ride->id,
            'patient_profile_id' => $ride->patient_profile_id,
            'type' => $type,
            'amount' => round($amount, 2),
            'status' => RideCharge::STATUS_PENDING,
            'method' => $method,
            'gateway' => class_basename($this->gateway),
            'note' => $note ? mb_substr($note, 0, 255) : null,
        ]);
        $charge->setRelation('ride', $ride);

        // Upfront hold secured at request time: capture this fare / fee from it (rest refunded).
        try {
            $holds = app(\App\Services\Payments\RidePaymentService::class);
            $hold = $holds->intentFor($ride);
            $captured = $holds->captureForCharge($ride, $charge);
            if ($captured > 0 && $hold) {
                $short = round((float) $charge->amount - $captured, 2);
                $charge->update([
                    'amount' => $captured,
                    'status' => RideCharge::STATUS_PAID,
                    'gateway' => $hold->gateway,
                    'gateway_ref' => $hold->gateway_ref ?: $hold->reference,
                ]);
                if ($short > 0) {
                    // Final fare above the held amount: the difference is a normal (payable) charge.
                    $this->charge($ride, $type, $short, trim(($note ? $note.' — ' : '').'balance above upfront amount'), $method);
                }

                return $charge->fresh();
            }
        } catch (\Throwable $e) {
            Log::warning('[RideChargeService] hold capture failed: '.$e->getMessage());
        }

        try {
            $result = $this->gateway->charge($charge, $method);
            // Online gateways never settle synchronously: the charge stays pending until a
            // verified webhook marks it paid (card on file) or the patient pays from the payment sheet.
            $charge->update([
                'status' => ! empty($result['success']) ? RideCharge::STATUS_PAID : (! empty($result['pending']) ? RideCharge::STATUS_PENDING : RideCharge::STATUS_FAILED),
                'gateway_ref' => $result['reference'] ?? null,
                'gateway' => $result['gateway'] ?? $charge->gateway,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[RideChargeService] charge failed: '.$e->getMessage());
            $charge->update(['status' => RideCharge::STATUS_FAILED]);
        }

        return $charge->fresh();
    }

    /**
     * Waive an unpaid fee, or refund a paid one. Returns a human message.
     */
    public function waiveOrRefund(RideCharge $charge, ?User $by, ?string $note = null): string
    {
        if ($charge->status === RideCharge::STATUS_PAID) {
            $result = $this->gateway->refund((string) $charge->gateway_ref, (float) $charge->amount);
            if (empty($result['success'])) {
                return 'Refund failed: '.($result['message'] ?? 'gateway error');
            }
            $charge->update([
                'status' => RideCharge::STATUS_REFUNDED,
                'refunded_at' => now(),
                'actioned_by_user_id' => $by?->id,
                'note' => mb_substr(trim(($charge->note ? $charge->note.' | ' : '').'Refunded: '.($note ?: 'by admin')), 0, 255),
            ]);
            $msg = 'Refunded R '.number_format((float) $charge->amount, 2).'.';
            $newStatus = RideCharge::STATUS_REFUNDED;
        } elseif (in_array($charge->status, [RideCharge::STATUS_PENDING, RideCharge::STATUS_FAILED], true)) {
            $charge->update([
                'status' => RideCharge::STATUS_WAIVED,
                'actioned_by_user_id' => $by?->id,
                'note' => mb_substr(trim(($charge->note ? $charge->note.' | ' : '').'Waived: '.($note ?: 'by admin')), 0, 255),
            ]);
            $msg = 'Fee waived.';
            $newStatus = RideCharge::STATUS_WAIVED;
        } else {
            return 'This charge is already '.$charge->status.'.';
        }

        if (in_array($charge->type, [RideCharge::TYPE_CANCELLATION, RideCharge::TYPE_NO_SHOW], true)) {
            $charge->ride?->forceFill(['cancellation_fee_status' => $newStatus])->save();
        }

        return $msg;
    }
}
