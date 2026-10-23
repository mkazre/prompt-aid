<?php

namespace App\Events;

use App\Models\PaymentIntent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money is now ours: an immediate payment was confirmed by a verified
 * webhook / status query, or a hold was captured (fully or partly).
 * For ledgers / commission: payableType is the alias (invoice, order,
 * ride_charge, ride, consult, subscription), amount is THIS capture.
 */
class PaymentCaptured
{
    use Dispatchable;

    public function __construct(
        public PaymentIntent $intent,
        public float $amount,
        public ?string $payableType,
        public ?int $payableId,
        public string $gateway,
        public ?string $gatewayRef = null,
    ) {}
}
