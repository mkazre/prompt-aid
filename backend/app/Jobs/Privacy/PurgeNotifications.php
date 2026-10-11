<?php

namespace App\Jobs\Privacy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes in-app notifications (user_notifications), Filament/database
 * notifications (notifications) and the delivery log (notifications_log)
 * older than privacy_retention_notifications_days.
 */
class PurgeNotifications extends RetentionJob
{
    protected function settingKey(): ?string
    {
        return 'privacy_retention_notifications_days';
    }

    protected function purge(Carbon $cutoff): int
    {
        $total = 0;

        foreach (['user_notifications', 'notifications_log'] as $table) {
            if (Schema::hasTable($table)) {
                $total += $this->chunkDelete(fn () => DB::table($table)->where('created_at', '<', $cutoff), $table);
            }
        }

        // Laravel's notifications table has a UUID primary key.
        if (Schema::hasTable('notifications')) {
            for ($i = 0; $i < self::MAX_BATCHES; $i++) {
                $ids = DB::table('notifications')->where('created_at', '<', $cutoff)->limit(self::BATCH)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }
                $total += DB::table('notifications')->whereIn('id', $ids)->delete();
            }
        }

        return $total;
    }
}
