<?php

namespace App\Services\Safety;

/** A user-facing trip-recording upload error with the HTTP status to return. */
class RecordingException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
