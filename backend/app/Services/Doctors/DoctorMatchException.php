<?php

namespace App\Services\Doctors;

/** A user-facing rule violation in the on-demand matching flow (message is safe to show). */
class DoctorMatchException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
