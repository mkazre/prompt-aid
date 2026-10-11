<?php

namespace App\Services\Rides;

/** A ride action that is not allowed right now; the message is safe to show the user. Controllers map it to HTTP 409/422. */
class RideActionException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
