<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by RideDispatchService on every ride status transition (incl.
 * re-dispatch back to "requested" after a driver cancels). Listeners run
 * synchronously (no queue worker on shared hosting) — keep them fast and
 * never throw.
 *
 * $actor: patient | driver | admin | system | simulator
 */
class RideStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Ride $ride,
        public ?string $from,
        public string $to,
        public string $actor = 'system',
    ) {}
}
