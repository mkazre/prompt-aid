<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\RideCharge;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Knows, per payable type, who owns it, whether it can be paid right now,
 * the server-side amount, and what "paid" / "refunded" means for it.
 */
class Payables
{
    /** Load + authorise a payable for this user. Throws RuntimeException with a user-facing message. */
    public function resolve(string $alias, int $id, User $user): Model
    {
        $class = PaymentIntent::PAYABLE_TYPES[$alias] ?? null;
        if (! $class) {
            throw new RuntimeException('Unknown item to pay for.');
        }
        /** @var Model|null $model */
        $model = $class::query()->find($id);
        if (! $model || ! $this->owns($model, $user)) {
            throw new RuntimeException('We could not find that item on your account.');
        }
        $this->assertPayable($model);

        return $model;
    }

    public function owns(Model $model, User $user): bool
    {
        $patientId = $user->patientProfile?->id;

        return match (true) {
            $model instanceof Subscription => (int) $model->user_id === (int) $user->id,
            $model instanceof Invoice, $model instanceof Order, $model instanceof RideCharge => $patientId && (int) $model->patient_profile_id === (int) $patientId,
            default => false,
        };
    }

    public function assertPayable(Model $model): void
    {
        $ok = match (true) {
            $model instanceof Invoice => ! in_array($model->status, [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED], true),
            $model instanceof Order => $model->payment_status === 'unpaid' && $model->status === Order::STATUS_PENDING_PAYMENT,
            $model instanceof RideCharge => in_array($model->status, [RideCharge::STATUS_PENDING, RideCharge::STATUS_FAILED], true),
            $model instanceof Subscription => $model->status === Subscription::STATUS_PENDING,
            default => false,
        };
        if (! $ok) {
            $msg = $model instanceof Order && $model->status === Order::STATUS_AWAITING_PRESCRIPTION
                ? 'This order is awaiting prescription approval and cannot be paid yet.'
                : 'This item is already paid or can no longer be paid.';
            throw new RuntimeException($msg);
        }
        if ($this->amountFor($model) <= 0) {
            throw new RuntimeException('There is nothing to pay.');
        }
    }

    public function amountFor(Model $model): float
    {
        return round(match (true) {
            $model instanceof Invoice => (float) $model->total,
            $model instanceof Order => (float) $model->total,
            $model instanceof RideCharge => (float) $model->amount,
            $model instanceof Subscription => (float) $model->amount,
            default => 0,
        }, 2);
    }

    public function serviceFor(Model $model): string
    {
        return match (true) {
            $model instanceof Order => 'pharmacy',
            $model instanceof RideCharge => 'ride',
            $model instanceof Subscription => 'subscription',
            default => 'invoice',
        };
    }

    public function describe(Model $model): string
    {
        return match (true) {
            $model instanceof Invoice => 'PromptAid invoice '.$model->invoice_no,
            $model instanceof Order => 'PromptAid pharmacy order '.$model->order_no,
            $model instanceof RideCharge => 'PromptAid shuttle '.strtolower($model->typeLabel()).' '.$model->getPayableReference(),
            $model instanceof Subscription => 'PromptAid '.($model->plan?->name ?? 'subscription'),
            default => 'PromptAid payment',
        };
    }

    /** Apply "paid" to the payable. Idempotent. */
    public function markPaid(Model $model, PaymentIntent $intent): void
    {
        if ($model instanceof Invoice) {
            $exists = Payment::query()->where('invoice_id', $model->id)->where('gateway_ref', $intent->gateway_ref ?: $intent->reference)->exists();
            if (! $exists) {
                Payment::query()->create([
                    'invoice_id' => $model->id,
                    'method' => 'card',
                    'amount' => $intent->amount,
                    'status' => 'success',
                    'gateway' => $intent->gateway,
                    'gateway_ref' => $intent->gateway_ref ?: $intent->reference,
                ]);
            }
            if ($model->status !== Invoice::STATUS_PAID) {
                $model->update(['status' => Invoice::STATUS_PAID]);
            }
        } elseif ($model instanceof Order) {
            $update = ['payment_status' => 'paid'];
            if ($model->status === Order::STATUS_PENDING_PAYMENT) {
                $update['status'] = Order::STATUS_CONFIRMED;
            }
            $model->update($update);
        } elseif ($model instanceof RideCharge) {
            if ($model->status !== RideCharge::STATUS_PAID) {
                $model->update([
                    'status' => RideCharge::STATUS_PAID,
                    'gateway' => $intent->gateway,
                    'gateway_ref' => $intent->gateway_ref ?: $intent->reference,
                ]);
                if (in_array($model->type, [RideCharge::TYPE_CANCELLATION, RideCharge::TYPE_NO_SHOW], true)) {
                    $model->ride?->forceFill(['cancellation_fee_status' => RideCharge::STATUS_PAID])->save();
                }
            }
        }
        // Subscriptions are handled by SubscriptionService (activation + renewals).
    }

    public function markRefunded(Model $model, PaymentIntent $intent, float $amount): void
    {
        if ($model instanceof Invoice) {
            Payment::query()->where('invoice_id', $model->id)->where('gateway_ref', $intent->gateway_ref ?: $intent->reference)->update(['status' => 'refunded']);
            if ($amount + 0.009 >= $intent->amount) {
                $model->update(['status' => Invoice::STATUS_REFUNDED]);
            }
        } elseif ($model instanceof Order) {
            if ($amount + 0.009 >= $intent->amount) {
                $model->update(['payment_status' => 'refunded']);
            }
        } elseif ($model instanceof RideCharge) {
            $model->update(['status' => RideCharge::STATUS_REFUNDED, 'refunded_at' => now()]);
            if (in_array($model->type, [RideCharge::TYPE_CANCELLATION, RideCharge::TYPE_NO_SHOW], true)) {
                $model->ride?->forceFill(['cancellation_fee_status' => RideCharge::STATUS_REFUNDED])->save();
            }
        }
    }
}
