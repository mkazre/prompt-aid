<?php

namespace App\Jobs\Privacy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes triage submissions made WITHOUT an account (user_id null) older
 * than privacy_retention_anon_triage_days. Submissions linked to a patient
 * account are clinical records and follow clinical retention instead.
 */
class PurgeAnonymousTriageSubmissions extends RetentionJob
{
    protected function settingKey(): ?string
    {
        return 'privacy_retention_anon_triage_days';
    }

    protected function purge(Carbon $cutoff): int
    {
        if (! Schema::hasTable('triage_submissions')) {
            return 0;
        }

        return $this->chunkDelete(
            fn () => DB::table('triage_submissions')->whereNull('user_id')->where('created_at', '<', $cutoff),
            'triage_submissions'
        );
    }
}
