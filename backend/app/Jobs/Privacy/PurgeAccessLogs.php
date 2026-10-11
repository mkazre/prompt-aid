<?php

namespace App\Jobs\Privacy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Deletes data_access_logs rows older than privacy_retention_access_logs_days. */
class PurgeAccessLogs extends RetentionJob
{
    protected function settingKey(): ?string
    {
        return 'privacy_retention_access_logs_days';
    }

    protected function purge(Carbon $cutoff): int
    {
        return $this->chunkDelete(fn () => DB::table('data_access_logs')->where('created_at', '<', $cutoff), 'data_access_logs');
    }
}
