<?php

namespace App\Support;

use App\Services\Commerce\DoctorAgendaService;
use App\Services\Commerce\DocumentExpiryService;

/**
 * Tick registrations for the commerce/alerts stream (see App\Support\Tick).
 * Both jobs are idempotent: the agenda alert keeps a per-doctor-per-day
 * cache marker, expiry reminders a row per threshold.
 */
class CommerceJobs
{
    public static function registerOnTick(): void
    {
        // Checked every 5 minutes; sends once per doctor per day after the admin-set time.
        Tick::register('doctor.daily_agenda', 300, fn () => app(DoctorAgendaService::class)->run());

        // Hourly is plenty for date-based thresholds (each reminder is sent once).
        Tick::register('documents.expiry_reminders', 3600, fn () => app(DocumentExpiryService::class)->run());
    }
}
