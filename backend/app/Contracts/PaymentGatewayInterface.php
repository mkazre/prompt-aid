<?php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    /**
     * Charge a payable (invoice or order) and return a normalized result.
     *
     * @return array{success: bool, reference: string, message: string}
     */
    public function charge(Payable $payable, string $method): array;

    /**
     * Refund a previously successful payment reference.
     *
     * @return array{success: bool, message: string}
     */
    public function refund(string $reference, float $amount): array;
}
