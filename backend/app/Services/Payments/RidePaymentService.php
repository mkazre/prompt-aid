<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\Ride;
use App\Models\RideCharge;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\Log;

/**
 * Shuttle upfront hold: when a trip is requested the patient secures the
 * quoted fare (+ a small buffer for route changes) — POST payments/intents
 * {payable_type: "ride", payable_id}. On completion RideChargeService
 * captures the final fare from the hold (remainder refunded); cancellation /
 * no-show fees are captured the same way; a free cancellation releases it.
 * Test mode: the hold is simulated automatically when the ride is created.
 */
class RidePaymentService
{
    public function __construct(protected PaymentService $payments) {}

    public static function holdAmount(Ride $ride): float
    {
        $base = (float) ($ride->quoted_fare ?: $ride->fare_estimate ?: 0);
        $buffer = max(0, min(100, PaymentSettings::float('ride_hold_buffer_percent')));

        return round($base * (1 + $buffer / 100), 2);
    }

    public function enabled(): bool
    {
        return PaymentSettings::bool('ride_upfront_hold');
    }

    /**
     * Uber-style gate: with a live gateway the ride is NOT offered to drivers
     * until the hold is secured. Test mode (no gateway) auto-secures instead.
     */
    public function requiresUpfront(): bool
    {
        return $this->enabled() && $this->payments->gateways()->onlineActive() && ! $this->payments->gateways()->mockActive();
    }

    public function awaiting(Ride $ride): bool
    {
        return $ride->status === Ride::STATUS_REQUESTED && $ride->dispatch_state === Ride::DISPATCH_AWAITING_PAYMENT;
    }

    /** Payment block for the ride API / tracking page. */
    public function payload(Ride $ride): array
    {
        $intent = $this->intentFor($ride);

        return [
            'required' => $this->awaiting($ride),
            'state' => $this->awaiting($ride) ? 'awaiting' : ($intent ? $intent->status : 'not_required'),
            'amount' => self::holdAmount($ride),
            'payable_type' => 'ride',
            'payable_id' => $ride->id,
            'pay_by' => $this->awaiting($ride) ? $ride->created_at?->copy()->addMinutes(max(2, PaymentSettings::int('ride_payment_window_minutes')))->toIso8601String() : null,
        ];
    }

    /** Unpaid ride requests lapse after the payment window and never reach drivers. */
    public function expireUnpaid(): int
    {
        $n = 0;
        Ride::query()->where('status', Ride::STATUS_REQUESTED)->where('dispatch_state', Ride::DISPATCH_AWAITING_PAYMENT)
            ->where('created_at', '<', now()->subMinutes(max(2, PaymentSettings::int('ride_payment_window_minutes'))))
            ->limit(100)->get()
            ->each(function (Ride $ride) use (&$n) {
                app(\App\Services\Rides\RideDispatchService::class)->expireRide($ride, 'payment_not_completed', 'Payment was not completed in time — the trip was not sent to drivers.');
                $this->release($ride, 'Ride payment window lapsed');
                $n++;
            });

        return $n;
    }

    public function secured(Ride $ride, PaymentIntent $intent): bool
    {
        $ride = $ride->fresh();
        if ((int) $ride->payment_intent_id === (int) $intent->id) {
            return true;
        }
        if ($ride->payment_intent_id || in_array($ride->status, ['completed', 'cancelled'], true)) {
            return false;
        }
        $ride->forceFill(['payment_intent_id' => $intent->id])->saveQuietly();
        if ($this->awaiting($ride)) {
            // Paid: now the trip goes to drivers (or the queue for scheduled / manual dispatch).
            $state = $ride->dispatch_after_payment ?: Ride::DISPATCH_SEARCHING;
            $ride->forceFill([
                'dispatch_state' => $state,
                'dispatch_started_at' => $state === Ride::DISPATCH_SEARCHING ? now() : null,
                'dispatch_after_payment' => null,
            ])->saveQuietly();
            if ($state === Ride::DISPATCH_SEARCHING) {
                app(\App\Services\Rides\RideDispatchService::class)->advance($ride->fresh());
            }
        }

        return true;
    }

    public function intentFor(Ride $ride): ?PaymentIntent
    {
        return $ride->payment_intent_id ? PaymentIntent::query()->find($ride->payment_intent_id) : null;
    }

    public function isSecured(Ride $ride): bool
    {
        return $this->intentFor($ride)?->status === PaymentIntent::STATUS_AUTHORIZED;
    }

    /** Test mode: simulate the hold as soon as the ride exists. */
    public function autoHoldInTestMode(Ride $ride): void
    {
        if (! $this->enabled() || ! $this->payments->gateways()->mockActive() || self::holdAmount($ride) <= 0) {
            return;
        }
        try {
            $user = $ride->patient?->user;
            if (! $user) {
                return;
            }
            [$intent] = $this->payments->start(new PayableIntent(
                user: $user, payable: $ride, service: 'ride', amount: self::holdAmount($ride),
                description: $this->payments->payables()->describe($ride), gateway: 'mock', channel: 'server',
            ));
            $this->payments->completeMock($intent);
        } catch (\Throwable $e) {
            Log::info('[RidePayment] test-mode hold skipped: '.$e->getMessage());
        }
    }

    /**
     * Settle a fare / fee charge from the hold. Returns the amount captured
     * (0 when there is no hold). Partial capture refunds the rest immediately.
     */
    public function captureForCharge(Ride $ride, RideCharge $charge): float
    {
        $intent = $this->intentFor($ride);
        if (! $intent || $intent->status !== PaymentIntent::STATUS_AUTHORIZED) {
            return 0.0;
        }

        return $this->payments->capture($intent, (float) $charge->amount, $charge->typeLabel());
    }

    public function release(Ride $ride, string $reason): void
    {
        $intent = $this->intentFor($ride);
        if ($intent) {
            $this->payments->release($intent, $reason);
        }
    }
}
