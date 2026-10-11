<?php

namespace App\Providers;

use App\Jobs\Matching\ExpireDoctorMatches;
use App\Models\Appointment;
use App\Observers\DoctorMatchAppointmentObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Wires "See a doctor now" into the rest of the app without touching the
 * shared AppServiceProvider: the Appointment observer (doctor release +
 * cancellation settlement) and the shared Tick background runner.
 */
class DoctorMatchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Appointment::observe(DoctorMatchAppointmentObserver::class);

        // Cron-optional background runner (App\Support\Tick, shuttle work).
        // Patient/doctor polling already progresses requests lazily; this is
        // the safety net for lapsed offers/expiries when nobody is polling.
        $tick = 'App\\Support\\Tick';
        if (class_exists($tick) && method_exists($tick, 'register')) {
            $tick::register('doctor-match.expire', 30, fn () => (new ExpireDoctorMatches)());
        }
    }
}
