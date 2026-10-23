<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by the consult hub when a consult is over.
 * $outcome e.g. completed | doctor_no_show | doctor_cancelled | patient_no_show | no_answer | failed.
 * Payments listens: completed / patient_no_show → capture (if not yet);
 * doctor_no_show / doctor_cancelled / failed / no_answer → full release/refund.
 */
class ConsultEnded
{
    use Dispatchable;

    public function __construct(
        public Appointment $appointment,
        public string $outcome,
    ) {}
}
