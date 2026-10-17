<?php

namespace App\Services\Payments;

/** Normalised outcome of a gateway webhook after verification. */
class WebhookResult
{
    public function __construct(
        public bool $verified,
        public string $note = '',
        /** Our PaymentIntent.reference (m_payment_id / Paystack reference / Ozow TransactionReference). */
        public ?string $reference = null,
        /** paid | failed | cancelled | pending | refunded | ignored */
        public string $status = 'ignored',
        public ?string $gatewayRef = null,
        public ?float $amount = null,
        public ?string $token = null,
        public string $eventType = 'payment',
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}

    public static function rejected(string $note, array $payload = [], ?string $reference = null): self
    {
        return new self(false, $note, $reference, 'ignored', payload: $payload);
    }
}
