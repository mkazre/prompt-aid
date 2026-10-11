<?php

namespace App\Jobs\Privacy;

use App\Support\PrivacySettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Base for POPIA retention sweeps. Plain invokable classes (no queue on
 * shared hosting) run from Tick (see RetentionJobs::registerOnTick) or
 * `php artisan privacy:retention`.
 *
 * Safety: each run deletes at most MAX_BATCHES × BATCH rows per job so it
 * finishes well inside Tick's 120 s lock on shared hosting; any backlog is
 * cleared over subsequent runs. A retention value of 0 disables the job.
 */
abstract class RetentionJob
{
    public const BATCH = 500;

    public const MAX_BATCHES = 4;

    /** Settings key holding the retention period in days (null = no setting). */
    abstract protected function settingKey(): ?string;

    /** @return int rows affected */
    abstract protected function purge(Carbon $cutoff): int;

    public function __invoke(): int
    {
        $key = $this->settingKey();
        $days = $key ? PrivacySettings::int($key) : 1;

        if ($days <= 0) {
            return 0;
        }

        return $this->purge(now()->subDays($days));
    }

    /** Deletes matching rows by primary key in bounded batches. */
    protected function chunkDelete(callable $query, string $table): int
    {
        $total = 0;

        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            /** @var Builder $q */
            $q = $query();
            $ids = $q->orderBy('id')->limit(self::BATCH)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += \Illuminate\Support\Facades\DB::table($table)->whereIn('id', $ids)->delete();
            if ($ids->count() < self::BATCH) {
                break;
            }
        }

        return $total;
    }
}
