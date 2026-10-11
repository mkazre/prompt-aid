<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cron-optional background work for shared hosting.
 *
 * A registry of named jobs, each with a minimum interval. Tick::run()
 * executes whichever jobs are due, serialised by a cache lock so two
 * runners never overlap. It is triggered by any of:
 *   (a) `php artisan schedule:run` (routes/console.php schedules `tick:run`
 *       every minute) — if the host has cron;
 *   (b) RunTickAfterResponse middleware: after the response is sent, at
 *       most once per THROTTLE_SECONDS across all web/api requests;
 *   (c) GET /tick?key=... (key = Setting `tick_secret_key`, empty = off) for
 *       an external free uptime pinger.
 *
 * Register jobs from a service provider's boot():
 *   Tick::register('my.job', 60, fn () => app(MyService::class)->doWork());
 * Last-run times live in the cache (`tick:last:{name}`); losing them only
 * makes a job run one extra time.
 */
class Tick
{
    public const THROTTLE_SECONDS = 30;

    /** @var array<string, array{interval: int, callback: callable}> */
    protected static array $jobs = [];

    public static function register(string $name, int $intervalSeconds, callable $callback): void
    {
        static::$jobs[$name] = ['interval' => max(1, $intervalSeconds), 'callback' => $callback];
    }

    /** @return array<string, int> name => interval */
    public static function jobs(): array
    {
        return array_map(fn ($j) => $j['interval'], static::$jobs);
    }

    /**
     * Run every due job. Never throws.
     *
     * @return array<string, string> name => "ok" | "skipped" | "error: ..."
     */
    public static function run(bool $force = false): array
    {
        $report = [];

        try {
            $lock = Cache::lock('tick:run', 120);
            if (! $lock->get()) {
                return ['_runner' => 'busy'];
            }
        } catch (\Throwable $e) {
            Log::warning('[Tick] lock unavailable: '.$e->getMessage());

            return ['_runner' => 'lock-error'];
        }

        try {
            Cache::put('tick:last_run', now()->toIso8601String(), 86400 * 7);

            foreach (static::$jobs as $name => $job) {
                $lastKey = "tick:last:{$name}";
                $last = (int) Cache::get($lastKey, 0);
                if (! $force && time() - $last < $job['interval']) {
                    $report[$name] = 'skipped';

                    continue;
                }
                Cache::put($lastKey, time(), 86400 * 30);

                try {
                    ($job['callback'])();
                    $report[$name] = 'ok';
                } catch (\Throwable $e) {
                    Log::error("[Tick] job {$name} failed: ".$e->getMessage());
                    $report[$name] = 'error: '.$e->getMessage();
                }
            }
        } finally {
            $lock->release();
        }

        return $report;
    }

    /** Called by the middleware after the response: at most once per THROTTLE_SECONDS. */
    public static function maybeRunAfterResponse(): void
    {
        try {
            if (empty(static::$jobs) || ! Cache::add('tick:throttle', 1, self::THROTTLE_SECONDS)) {
                return;
            }
            static::run();
        } catch (\Throwable $e) {
            Log::warning('[Tick] after-response run failed: '.$e->getMessage());
        }
    }

    public static function secretKey(): string
    {
        return trim((string) Setting::get('tick_secret_key', ''));
    }
}
