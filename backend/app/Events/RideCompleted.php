<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired once when a ride is completed; $ride->fare_final / fare_breakdown['final'] are already set. */
class RideCompleted
{
    use Dispatchable;

    public function __construct(public Ride $ride) {}
}
