<?php

namespace App\Support;

use App\Models\PushToken;
use App\Models\Setting;
use App\Services\Notifications\ExpoPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Checks behind Staff → System → System health. Every check returns
 * {label, status: ok|warn|fail|info, value, help}; none of them throws.
 */
class SystemHealth
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const INFO = 'info';

    /** @return array<int, array{label: string, status: string, value: string, help: ?string}> */
    public static function checks(): array
    {
        $checks = [];
        foreach ([
            'php', 'database', 'migrations', 'storageLink', 'storageWritable', 'cache', 'tick',
            'mail', 'push', 'debug', 'appUrl', 'queue', 'disk', 'version',
        ] as $method) {
            try {
                $checks[] = static::$method();
            } catch (\Throwable $e) {
                $checks[] = static::row(Str::headline($method), self::FAIL, 'Check failed', $e->getMessage());
            }
        }

        return $checks;
    }

    /** @return array<int, array{name: string, interval: string, last: string, status: string}> */
    public static function tickJobs(): array
    {
        $rows = [];
        foreach (Tick::jobs() as $name => $interval) {
            $last = (int) Cache::get("tick:last:{$name}", 0);
            $age = $last ? time() - $last : null;
            $status = $last === 0 ? self::WARN : ($age <= max($interval * 3, 900) ? self::OK : self::WARN);
            $rows[] = [
                'name' => $name,
                'interval' => static::duration($interval),
                'last' => $last ? Carbon::createFromTimestamp($last)->timezone(config('app.timezone'))->format('d M H:i:s').' ('.Carbon::createFromTimestamp($last)->diffForHumans().')' : 'Never (since the cache was last cleared)',
                'status' => $status,
            ];
        }

        return $rows;
    }

    protected static function php(): array
    {
        $ok = PHP_VERSION_ID >= 80200;

        return static::row('PHP version', $ok ? self::OK : self::FAIL, PHP_VERSION, $ok ? null : 'Laravel 12 needs PHP 8.2 or newer. Pick a newer PHP version in cPanel → MultiPHP Manager.');
    }

    protected static function database(): array
    {
        try {
            DB::select('select 1');
            $driver = DB::connection()->getDriverName();
            $version = rescue(fn () => DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), '', false);

            return static::row('Database connection', self::OK, trim($driver.' '.$version).' · '.DB::connection()->getDatabaseName());
        } catch (\Throwable $e) {
            return static::row('Database connection', self::FAIL, 'Cannot connect', Str::limit($e->getMessage(), 200));
        }
    }

    protected static function migrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return static::row('Pending migrations', self::FAIL, 'Migrations table missing', 'Run "php artisan migrate --force".');
        }

        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $ran = $migrator->getRepository()->getRan();
        $pending = array_values(array_diff(array_keys($files), $ran));

        return $pending === []
            ? static::row('Pending migrations', self::OK, '0 pending ('.count($ran).' run)')
            : static::row('Pending migrations', self::FAIL, count($pending).' pending', 'Not yet run: '.implode(', ', array_slice($pending, 0, 5)).(count($pending) > 5 ? ' …' : '').'. Deploy again or run "php artisan migrate --force".');
    }

    protected static function storageLink(): array
    {
        $link = public_path('storage');
        if (is_link($link) || is_dir($link)) {
            return static::row('Storage symlink', self::OK, is_link($link) ? 'public/storage → '.readlink($link) : 'public/storage folder present');
        }

        // Uploads are also served by Laravel's own /storage route (filesystems.php "serve"), so this is not fatal.
        return static::row('Storage symlink', self::WARN, 'Missing', 'Uploaded files are still served through Laravel\'s /storage route, but a symlink is faster. Run "php artisan storage:link" if your host allows symlinks.');
    }

    protected static function storageWritable(): array
    {
        $bad = [];
        foreach (['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $dir;
            }
        }

        $probe = storage_path('app/.health-'.Str::random(8));
        $wrote = @file_put_contents($probe, 'ok') !== false;
        if ($wrote) {
            @unlink($probe);
        }

        return $bad === [] && $wrote
            ? static::row('Storage writable', self::OK, 'storage/ and bootstrap/cache are writable')
            : static::row('Storage writable', self::FAIL, 'Not writable: '.($bad ? implode(', ', $bad) : 'storage/app'), 'Set these folders to 755 (or 775) in cPanel → File Manager → Permissions.');
    }

    protected static function cache(): array
    {
        $key = 'health:probe:'.Str::random(8);
        $value = Str::random(16);
        Cache::put($key, $value, 60);
        $ok = Cache::get($key) === $value;
        Cache::forget($key);

        return static::row('Cache working', $ok ? self::OK : self::FAIL, config('cache.default').' store'.($ok ? '' : ' — read-back failed'), $ok ? null : 'Check CACHE_STORE in .env and that the cache table / folder exists.');
    }

    protected static function tick(): array
    {
        $last = Cache::get('tick:last_run');
        if (! $last) {
            return static::row('Background jobs (Tick)', self::WARN, 'No run recorded yet', 'Jobs run after web requests, from cron (schedule:run) or GET /tick?key=… from an uptime pinger. Use "Run background jobs now" to start them.');
        }

        $at = Carbon::parse($last);
        $minutes = $at->diffInMinutes(now());
        $status = $minutes <= 15 ? self::OK : ($minutes <= 120 ? self::WARN : self::FAIL);

        return static::row('Background jobs (Tick)', $status, 'Last run '.$at->timezone(config('app.timezone'))->format('d M H:i').' ('.$at->diffForHumans().')', $status === self::OK ? null : 'Nothing has triggered the background jobs recently. Set up a free uptime pinger on /tick?key=… (System → Settings → tick_secret_key) or a cPanel cron for "php artisan schedule:run".');
    }

    protected static function mail(): array
    {
        $mailer = (string) config('mail.default');
        $ok = ! in_array($mailer, ['log', 'array', ''], true);

        return static::row('Mail driver', $ok ? self::OK : self::FAIL, $mailer.($ok ? ' · from '.config('mail.from.address') : ''), $ok ? null : 'Emails are only written to the log. Set MAIL_MAILER=smtp and the MAIL_* settings in .env.');
    }

    protected static function push(): array
    {
        $enabled = ExpoPush::enabled();
        $tokens = PushToken::query()->count();
        $users = PushToken::query()->distinct()->count('user_id');

        return static::row(
            'Phone push notifications',
            $enabled ? ($tokens > 0 ? self::OK : self::WARN) : self::FAIL,
            ($enabled ? 'Enabled' : 'Disabled (Setting push_enabled)').' · '.$tokens.' '.Str::plural('device', $tokens).' / '.$users.' '.Str::plural('user', $users),
            $enabled ? ($tokens > 0 ? null : 'No phones have registered yet — devices register when someone signs in to the app and allows notifications.') : 'Turn push back on by setting push_enabled to 1 in System → Settings.',
        );
    }

    protected static function debug(): array
    {
        $debug = (bool) config('app.debug');

        return static::row('Debug mode off', $debug ? self::FAIL : self::OK, $debug ? 'APP_DEBUG=true' : 'APP_DEBUG=false', $debug ? 'Debug mode shows stack traces (and secrets) to visitors. Set APP_DEBUG=false in .env.' : null);
    }

    protected static function appUrl(): array
    {
        $url = (string) config('app.url');
        $https = str_starts_with($url, 'https://');

        return static::row('APP_URL uses https', $https ? self::OK : self::FAIL, $url ?: '(empty)', $https ? null : 'Links in emails, push notifications and the app use APP_URL. Set APP_URL=https://… in .env.');
    }

    protected static function queue(): array
    {
        $queue = (string) config('queue.default');
        $pending = null;
        if ($queue === 'database') {
            $pending = rescue(fn () => DB::table(config('queue.connections.database.table', 'jobs'))->count(), null, false);
        }

        return static::row(
            'Queue connection',
            $queue === 'sync' || ($pending === 0) ? self::OK : self::WARN,
            $queue.($pending !== null ? ' · '.$pending.' waiting '.Str::plural('job', (int) $pending) : ''),
            $queue === 'sync' ? null : 'The host runs no queue worker; everything important runs inline or via Tick. Queued jobs only run if a worker is started — QUEUE_CONNECTION=sync avoids surprises.',
        );
    }

    protected static function disk(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());
        if ($free === false) {
            return static::row('Disk free space', self::INFO, 'Not reported by the host');
        }

        $status = $free > 1024 ** 3 ? self::OK : ($free > 200 * 1024 ** 2 ? self::WARN : self::FAIL);

        return static::row('Disk free space', $status, static::bytes($free).' free'.($total ? ' of '.static::bytes($total) : ''), $status === self::OK ? null : 'Low disk space: clear old backups in ~/promptaid-backups and old logs in storage/logs.');
    }

    protected static function version(): array
    {
        $deployed = storage_path('app/deployed.txt');
        if (is_file($deployed)) {
            $info = trim((string) @file_get_contents($deployed));

            return static::row('App version', self::INFO, Str::limit(preg_replace('/\s+/', ' ', $info), 160));
        }

        $commit = static::gitCommit();

        return static::row('App version', self::INFO, $commit ? 'Commit '.$commit : 'Unknown', $commit ? null : 'Written to storage/app/deployed.txt by deploy/cpanel-deploy.sh on the next deploy.');
    }

    protected static function gitCommit(): ?string
    {
        foreach ([base_path('.git'), dirname(base_path()).'/.git'] as $git) {
            $refsDir = null;
            // Worktrees / submodules: ".git" is a file pointing at the real git dir.
            if (is_file($git) && preg_match('/^gitdir:\s*(.+)$/m', (string) @file_get_contents($git), $m)) {
                $git = trim($m[1]);
                if (! str_starts_with($git, '/')) {
                    $git = dirname(base_path()).'/'.$git;
                }
            }
            $head = $git.'/HEAD';
            if (is_file($git.'/commondir')) {
                $common = trim((string) @file_get_contents($git.'/commondir'));
                $refsDir = str_starts_with($common, '/') ? $common : $git.'/'.$common;
            }
            if (! is_file($head)) {
                continue;
            }
            $ref = trim((string) @file_get_contents($head));
            if (str_starts_with($ref, 'ref: ')) {
                $refPath = ($refsDir ?? $git).'/'.substr($ref, 5);
                $hash = is_file($refPath) ? trim((string) @file_get_contents($refPath)) : null;
                if (! $hash && is_file(($refsDir ?? $git).'/packed-refs')) {
                    foreach (file(($refsDir ?? $git).'/packed-refs') ?: [] as $line) {
                        if (str_ends_with(trim($line), substr($ref, 5))) {
                            $hash = strtok($line, ' ');
                        }
                    }
                }

                return $hash ? substr($hash, 0, 10).' ('.basename(substr($ref, 5)).')' : null;
            }

            return substr($ref, 0, 10);
        }

        return null;
    }

    /** @return array<int, string> the cache commands that would be rebuilt (what is cached right now) */
    public static function cachedNow(): array
    {
        $plan = [];
        if (app()->configurationIsCached()) {
            $plan[] = 'config:cache';
        }
        if (app()->routesAreCached()) {
            $plan[] = 'route:cache';
        }
        if (count(File::glob(config('view.compiled', storage_path('framework/views')).'/*.php') ?: []) > 0) {
            $plan[] = 'view:cache';
        }

        return $plan;
    }

    /**
     * optimize:clear, then rebuild only the caches that were built before.
     *
     * @return array{cleared: bool, rebuilt: array<int, string>, errors: array<int, string>}
     */
    public static function clearCaches(bool $quiet = false): array
    {
        $plan = static::cachedNow();
        $errors = [];
        $rebuilt = [];
        try {
            Artisan::call('optimize:clear');
        } catch (\Throwable $e) {
            $errors[] = 'optimize:clear: '.$e->getMessage();
        }

        foreach ($plan as $command) {
            try {
                Artisan::call($command);
                $rebuilt[] = $command;
            } catch (\Throwable $e) {
                $errors[] = $command.': '.$e->getMessage();
            }
        }

        // Settings live in the cache too; they are re-read from the database from now on.
        if ($errors) {
            \Illuminate\Support\Facades\Log::warning('[SystemHealth] clearCaches: '.implode('; ', $errors));
        }

        return ['cleared' => $errors === [], 'rebuilt' => $rebuilt, 'errors' => $errors];
    }

    protected static function row(string $label, string $status, string $value, ?string $help = null): array
    {
        return ['label' => $label, 'status' => $status, 'value' => $value, 'help' => $help];
    }

    protected static function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i >= 3 ? 1 : 0).' '.$units[$i];
    }

    protected static function duration(int $seconds): string
    {
        return match (true) {
            $seconds % 86400 === 0 => ($seconds / 86400).'d',
            $seconds % 3600 === 0 => ($seconds / 3600).'h',
            $seconds % 60 === 0 => ($seconds / 60).'m',
            default => $seconds.'s',
        };
    }
}
