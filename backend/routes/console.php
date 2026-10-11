<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cron-optional background jobs (App\Support\Tick). The same jobs also run
// after web/API responses (throttled) and via /tick?key=..., so nothing
// requires cron; if cron exists, `* * * * * php artisan schedule:run` makes
// them punctual.
Artisan::command('tick:run {--force : Run every job regardless of its interval}', function () {
    foreach (\App\Support\Tick::run((bool) $this->option('force')) as $job => $result) {
        $this->line(str_pad($job, 34).$result);
    }
})->purpose('Run due background jobs (shuttle dispatch, ride series, retention...)');

Schedule::command('tick:run')->everyMinute()->withoutOverlapping();
