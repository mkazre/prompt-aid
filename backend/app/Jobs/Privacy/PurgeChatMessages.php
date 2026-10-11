<?php

namespace App\Jobs\Privacy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Deletes chat messages (and their attachment files) older than privacy_retention_messages_days. */
class PurgeChatMessages extends RetentionJob
{
    protected function settingKey(): ?string
    {
        return 'privacy_retention_messages_days';
    }

    protected function purge(Carbon $cutoff): int
    {
        if (! Schema::hasTable('messages')) {
            return 0;
        }

        $total = 0;
        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $rows = DB::table('messages')->where('created_at', '<', $cutoff)
                ->orderBy('id')->limit(self::BATCH)->get(['id', 'attachment_path']);
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                if ($row->attachment_path) {
                    try {
                        Storage::disk('local')->delete($row->attachment_path);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            $total += DB::table('messages')->whereIn('id', $rows->pluck('id'))->delete();
            if ($rows->count() < self::BATCH) {
                break;
            }
        }

        return $total;
    }
}
