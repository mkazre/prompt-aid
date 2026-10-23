<?php

namespace App\Events;

use App\Models\PaymentIntent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money went back to the customer: a refund, the unused part of a hold, or
 * a full release of a hold (no match, doctor no-show, …). $manual = done in
 * the gateway dashboard and only recorded here (e.g. Ozow without API access).
 */
class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public PaymentIntent $intent,
        public float $amount,
        public ?string $payableType,
        public ?int $payableId,
        public string $gateway,
        public string $reason = 'Refund',
        public bool $manual = false,
    ) {}
}
