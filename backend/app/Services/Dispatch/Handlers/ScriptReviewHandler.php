<?php

namespace App\Services\Dispatch\Handlers;

use App\Models\DispatchJob;
use App\Models\PrescriptionUpload;
use App\Models\User;
use App\Services\Dispatch\DispatchException;
use Illuminate\Support\Collection;

/**
 * A prescription (script) uploaded with an order needs a pharmacist's
 * review. The order's pharmacy gets Accept (= "I'm reviewing it", the
 * review itself is approve/reject in the vendor panel) / Decline.
 */
class ScriptReviewHandler extends BaseHandler
{
    public function service(): string
    {
        return 'script_review';
    }

    protected function upload(DispatchJob $job): ?PrescriptionUpload
    {
        return PrescriptionUpload::query()->with(['patient.user', 'order.pharmacy.vendor'])->find($job->subject_id);
    }

    public function candidates(DispatchJob $job): Collection
    {
        $u = $this->upload($job);
        $vendor = $u?->order?->pharmacy?->status === 'active' ? $u->order->pharmacy->vendor : null;

        return $vendor ? collect([['user' => $vendor, 'distance_km' => null]]) : collect();
    }

    public function offerTtl(DispatchJob $job): int
    {
        return 15 * 60;
    }

    public function stillOpen(DispatchJob $job): bool
    {
        return PrescriptionUpload::query()->whereKey($job->subject_id)->where('status', 'pending_review')->whereNull('reviewed_by')->exists();
    }

    public function onAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $n = PrescriptionUpload::query()->whereKey($job->subject_id)->where('status', 'pending_review')->whereNull('reviewed_by')
            ->update(['reviewed_by' => $provider->id, 'updated_at' => now()]);
        if (! $n) {
            throw new DispatchException('This script is already being reviewed.', 409);
        }
    }

    public function onDeclined(DispatchJob $job, User $provider, ?string $reason): void
    {
        DispatchJob::query()->whereKey($job->id)->where('status', DispatchJob::SEARCHING)
            ->update(['status' => DispatchJob::MANUAL, 'closed_reason' => 'declined_by_provider', 'updated_at' => now()]);
        $this->alertAdmins($job, 'Pharmacy declined a script review'.($reason ? ' ('.$reason.')' : ''));
    }

    public function onExpired(DispatchJob $job): void
    {
        $this->alertAdmins($job, 'Script still waiting for review');
    }

    public function card(DispatchJob $job): array
    {
        $u = $this->upload($job);

        return [
            'icon' => 'file',
            'title' => 'Script to review',
            'subtitle' => $u?->order ? 'Order '.$u->order->order_no.' · R '.number_format((float) $u->order->total, 2) : 'Prescription upload',
            'patient_first_name' => $this->firstName($u?->patient?->user?->name),
            'lines' => array_values(array_filter([$u?->notes ? 'Note: '.mb_substr((string) $u->notes, 0, 120) : null])),
            'earning' => null,
            'when' => null,
            'url' => '/vendor/orders',
        ];
    }

    public function canServe(DispatchJob $job, User $provider): bool
    {
        return $provider->role === User::ROLE_PHARMACY_ADMIN;
    }
}
