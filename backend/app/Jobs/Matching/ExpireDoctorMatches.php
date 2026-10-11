<?php

namespace App\Jobs\Matching;

use App\Services\Doctors\DoctorMatchService;

/**
 * Background sweep for "See a doctor now": expires lapsed offers and
 * re-offers to the next doctor, expires requests past their deadline and
 * old waitlist rows. Safe to run as often as you like (cache-locked).
 * Invoked by `php artisan doctor-match:tick` and by the Tick runner.
 */
class ExpireDoctorMatches
{
    public function __invoke(): int
    {
        return app(DoctorMatchService::class)->tick(force: true);
    }
}
