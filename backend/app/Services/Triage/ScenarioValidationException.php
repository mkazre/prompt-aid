<?php

namespace App\Services\Triage;

use RuntimeException;

/** A scenario that breaks the ordering / settings rules. ->errors holds every problem in plain English. */
class ScenarioValidationException extends RuntimeException
{
    /** @param  array<int, string>  $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
