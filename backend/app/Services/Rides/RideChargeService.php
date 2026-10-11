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

        try {
            $result = $this->gateway->charge($charge, $method);
            $charge->update([
                'status' => ! empty($result['success']) ? RideCharge::STATUS_PAID : RideCharge::STATUS_FAILED,
                'gateway_ref' => $result['reference'] ?? null,
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
