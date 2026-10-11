<?php

namespace App\Jobs\Privacy;

use App\Support\Tick;

/**
 * Registry of POPIA retention jobs. Registered on Tick (hourly; each run
 * is bounded, see RetentionJob) from AppServiceProvider::boot(), and
 * runnable by hand with `php artisan privacy:retention [name]`.
 */
class RetentionJobs
{
    public const INTERVAL_SECONDS = 3600;

    /** @var array<string, class-string<RetentionJob>> */
    public const JOBS = [
        'privacy.purge_messages' => PurgeChatMessages::class,
        'privacy.purge_notifications' => PurgeNotifications::class,
        'privacy.purge_audit_logs' => PurgeAuditLogs::class,
        'privacy.purge_access_logs' => PurgeAccessLogs::class,
        'privacy.purge_dsr_exports' => PurgeDsrExports::class,
        'privacy.purge_anon_triage' => PurgeAnonymousTriageSubmissions::class,
        'privacy.finalise_clinical_anonymisation' => AnonymiseExpiredClinicalRecords::class,
    ];

    public static function registerOnTick(): void
    {
        if (! class_exists(Tick::class)) {
            return;
        }

        foreach (self::JOBS as $name => $class) {
            Tick::register($name, self::INTERVAL_SECONDS, fn () => app($class)());
        }
    }

    /** @return array<string, int|string> name => rows affected | error */
    public static function runAll(?string $only = null): array
    {
        $report = [];
        foreach (self::JOBS as $name => $class) {
            if ($only && $only !== $name) {
                continue;
            }
            try {
                $report[$name] = app($class)();
            } catch (\Throwable $e) {
                report($e);
                $report[$name] = 'error: '.$e->getMessage();
            }
        }

        return $report;
    }
}
