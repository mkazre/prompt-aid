<?php

namespace App\Jobs\Privacy;

use App\Models\DsrRequest;
use App\Services\Privacy\DataExportService;
use Illuminate\Support\Carbon;

/**
 * Deletes access-request export files once export_expires_at has passed
 * (export_expires_at = generated + privacy_retention_dsr_exports_days).
 * The DSR row itself is kept as the record that the request was handled.
 */
class PurgeDsrExports extends RetentionJob
{
    protected function settingKey(): ?string
    {
        return null;
    }

    protected function purge(Carbon $cutoff): int
    {
        $count = 0;
        $exports = app(DataExportService::class);

        DsrRequest::query()
            ->whereNotNull('export_path')
            ->where('export_expires_at', '<', now())
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get()
            ->each(function (DsrRequest $dsr) use ($exports, &$count) {
                $exports->deleteExport($dsr);
                $dsr->forceFill(['export_path' => null])->saveQuietly();
                $count++;
            });

        return $count;
    }
}
