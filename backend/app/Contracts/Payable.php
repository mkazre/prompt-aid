<?php

namespace App\Contracts;

/**
 * Anything that can be charged through PaymentGatewayInterface — a clinic
 * Invoice or a marketplace Order.
 */
interface Payable
{
    public function getTotal(): float;

    public function getPayableReference(): string;
}
