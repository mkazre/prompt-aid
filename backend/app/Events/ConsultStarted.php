<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by the consult hub when a consult actually starts (both parties
 * joined the video room, or the doctor connected the call back).
 * $channel = video | callback | in_person | phone.
 * Payments listens: an upfront hold for this consult is CAPTURED.
 */
class ConsultStarted
{
    use Dispatchable;

    public function __construct(
        public Appointment $appointment,
        public string $channel,
    ) {}
}
