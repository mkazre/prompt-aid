<?php

namespace App\Console\Commands;

use App\Services\Rides\RideSeriesService;
use Illuminate\Console\Command;

/**
 * Turns active RideSeries patterns into real, bookable Ride rows for the
 * next N days. Idempotent (skips dates that already have a materialised
 * ride) — recommended as a daily cron job so recurring shuttle schedules
 * (e.g. dialysis 3x/week) always have upcoming rides on the dispatch board
 * without a driver ever being auto-assigned to a ride that hasn't happened yet.
 */
class MaterializeRideSeries extends Command
{
    protected $signature = 'rides:materialize-series {--days=7 : How many days ahead to materialize}';

    protected $description = 'Create real Ride rows for upcoming occurrences of every active recurring ride series';

    public function handle(RideSeriesService $series): int
    {
        $created = $series->materializeUpcoming((int) $this->option('days'));

        $this->info("Materialized {$created->count()} ride(s) from active series.");

        foreach ($created as $ride) {
            $this->line("  #{$ride->id} {$ride->ride_ref} — scheduled for {$ride->scheduled_for}");
        }

        return self::SUCCESS;
    }
}
