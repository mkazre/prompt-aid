<?php

namespace App\Services\Dispatch;

class DispatchException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
