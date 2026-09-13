<?php

namespace App\Services\Payments;

use App\Contracts\Payable;
use App\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Str;

/**
 * Drop-in mock so the whole billing flow (invoice/order -> pay -> receipt)
 * works end-to-end without live credentials. Swap the binding in
 * AppServiceProvider for a real gateway (Stripe/Paystack/PayFast/etc.)
 * that implements the same PaymentGatewayInterface contract.
 */
class MockPaymentGateway implements PaymentGatewayInterface
{
    public function charge(Payable $payable, string $method): array
    {
        return [
            'success' => true,
            'reference' => 'MOCK-'.Str::upper(Str::random(12)),
            'message' => "Payment of {$payable->getTotal()} for {$payable->getPayableReference()} simulated successfully via {$method}.",
        ];
    }

    public function refund(string $reference, float $amount): array
    {
        return [
            'success' => true,
            'message' => "Refund of {$amount} for {$reference} simulated successfully.",
        ];
    }
}
