<?php

namespace App\Jobs\Privacy;

use App\Models\DsrRequest;
use App\Services\Privacy\AccountAnonymiser;
use Illuminate\Support\Carbon;

/**
 * For approved deletion requests whose clinical retention period
 * (retain_clinical_until) has now passed, strips the remaining identity and
 * clinical free text (AccountAnonymiser::finalise). 25 accounts per run.
 */
class AnonymiseExpiredClinicalRecords extends RetentionJob
{
    protected function settingKey(): ?string
    {
        return null;
    }

    protected function purge(Carbon $cutoff): int
    {
        $count = 0;
        $anonymiser = app(AccountAnonymiser::class);

        DsrRequest::query()
            ->where('type', DsrRequest::TYPE_DELETION)
            ->whereNotNull('anonymised_at')
            ->whereNull('fully_anonymised_at')
            ->whereNotNull('retain_clinical_until')
            ->whereDate('retain_clinical_until', '<=', now()->toDateString())
            ->orderBy('id')
            ->limit(25)
            ->get()
            ->each(function (DsrRequest $dsr) use ($anonymiser, &$count) {
                try {
                    $anonymiser->finalise($dsr);
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
