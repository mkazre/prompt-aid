<?php

namespace App\Jobs\Privacy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Deletes audit_logs rows older than privacy_retention_audit_logs_days. */
class PurgeAuditLogs extends RetentionJob
{
    protected function settingKey(): ?string
    {
        return 'privacy_retention_audit_logs_days';
    }

    protected function purge(Carbon $cutoff): int
    {
        return $this->chunkDelete(fn () => DB::table('audit_logs')->where('created_at', '<', $cutoff), 'audit_logs');
    }
}
