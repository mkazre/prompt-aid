<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Services\Consults\ConsultHubService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Consult hub wiring (kept out of the shared AppServiceProvider): when an
 * appointment's status changes anywhere else (doctor marks it completed in
 * the panel, admin sets no-show / cancelled), an open consult session is
 * closed so ConsultEnded still fires exactly once.
 */
class ConsultHubServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Appointment::updated(function (Appointment $appointment) {
            if (! $appointment->wasChanged('status')) {
                return;
            }
            try {
                app(ConsultHubService::class)->onAppointmentStatusChanged($appointment);
            } catch (\Throwable $e) {
                // Never block the status change that triggered this.
                Log::warning('[ConsultHub] status sync failed: '.$e->getMessage(), ['appointment_id' => $appointment->id]);
            }
        });
    }
}
