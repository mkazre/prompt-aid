<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once when a ride ends cancelled. $by = patient | admin | system | no_show.
 * (A driver cancelling an accepted ride does NOT end it — it is re-dispatched
 * and fires RideStatusChanged(to: requested, actor: driver) instead.)
 */
class RideCancelled
{
    use Dispatchable;

    public function __construct(
        public Ride $ride,
        public string $by,
        public float $fee = 0.0,
    ) {}
}
