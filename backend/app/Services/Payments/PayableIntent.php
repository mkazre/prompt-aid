<?php

namespace App\Services\Payments;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What the caller wants paid. The AMOUNT is always computed server-side
 * from the payable (Payables::amountFor), never taken from the client.
 */
class PayableIntent
{
    public function __construct(
        public User $user,
        public Model $payable,
        public string $service,
        public float $amount,
        public string $description,
        public ?string $gateway = null,
        public string $channel = 'web',
        public ?string $idempotencyKey = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}
}
