<?php

namespace App\Services\Dispatch\Handlers;

use App\Models\DispatchJob;
use App\Models\LabRequest;
use App\Models\ThirdPartyProfile;
use App\Models\User;
use App\Services\Dispatch\AvailabilityService;
use App\Services\Dispatch\DispatchException;
use App\Services\Lab\LabRequestService;
use Illuminate\Support\Collection;

/**
 * Unassigned lab request: broadcast to every active lab/imaging partner
 * who is online (heartbeat) and — when both locations are known — within
 * the radius. First accept takes it (the existing unassigned queue in the
 * partner panel keeps working; accepting there closes the alert too).
 */
class LabRequestHandler extends BaseHandler
{
    public const CATEGORIES = ['lab', 'imaging'];

    public function service(): string
    {
        return 'lab_request';
    }

    protected function req(DispatchJob $job): ?LabRequest
    {
        return LabRequest::query()->with(['patient.user', 'doctor.user', 'items'])->find($job->subject_id);
    }

    public function candidates(DispatchJob $job): Collection
    {
        $avail = app(AvailabilityService::class);
        $rows = collect();
        $partners = ThirdPartyProfile::query()->with('user')->where('status', 'active')
            ->where(fn ($q) => $q->whereIn('service_type', self::CATEGORIES)->orWhereIn('category', self::CATEGORIES))
            ->limit(300)->get();
        foreach ($partners as $p) {
            $u = $p->user;
            if (! $u || $u->status !== 'active' || ! $avail->isOnlineNow($u)) {
                continue;
            }
            $row = \App\Models\ProviderAvailability::query()->where('user_id', $u->id)->first();
            $d = $this->distanceKm(
                $job->lat !== null ? (float) $job->lat : null, $job->lng !== null ? (float) $job->lng : null,
                $row?->lat !== null ? (float) $row->lat : null, $row?->lng !== null ? (float) $row->lng : null,
            );
            if ($d !== null && $d > $this->radiusFor($job, $row?->max_radius_km !== null ? (float) $row->max_radius_km : null)) {
                continue;
            }
            $rows->push(['user' => $u, 'distance_km' => $d]);
        }

        return $rows->sortBy(fn ($r) => $r['distance_km'] ?? 9999)->values();
    }

    public function offerTtl(DispatchJob $job): int
    {
        return 5 * 60;
    }

    public function stillOpen(DispatchJob $job): bool
    {
        return LabRequest::query()->whereKey($job->subject_id)->where('status', LabRequest::STATUS_REQUESTED)->whereNull('third_party_profile_id')->exists();
    }

    public function onAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $tp = $provider->thirdPartyProfile;
        if (! $tp) {
            throw new DispatchException('Only partners can accept lab requests.', 403);
        }
        $n = LabRequest::query()->whereKey($job->subject_id)->where('status', LabRequest::STATUS_REQUESTED)->whereNull('third_party_profile_id')
            ->update(['third_party_profile_id' => $tp->id, 'status' => LabRequest::STATUS_ACCEPTED, 'accepted_at' => now(), 'updated_at' => now()]);
        if (! $n) {
            throw new DispatchException('This request was already taken.', 409);
        }
    }

    public function afterAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $req = LabRequest::query()->find($job->subject_id);
        if ($req && $provider->thirdPartyProfile) {
            // Same notifications as accepting from the partner queue.
            app(LabRequestService::class)->accept($req, $provider->thirdPartyProfile);
        }
    }

    public function onExpired(DispatchJob $job): void
    {
        $this->alertAdmins($job, 'No partner accepted a lab request (still in the unassigned queue)');
    }

    public function card(DispatchJob $job): array
    {
        $r = $this->req($job);

        return [
            'icon' => 'beaker',
            'title' => ($r?->priority === 'urgent' ? 'URGENT ' : '').'Lab request '.($r?->request_ref ?? ''),
            'subtitle' => $r ? $r->items->count().' test'.($r->items->count() === 1 ? '' : 's').' · Dr. '.($r->doctor?->user?->name ?? '') : 'No longer available',
            'patient_first_name' => $this->firstName($r?->patient?->user?->name),
            'lines' => array_values(array_filter([$r?->collection_address ? 'Collect at '.$r->collection_address : null])),
            'earning' => null,
            'when' => null,
            'url' => '/partner/lab-requests',
        ];
    }

    public function canServe(DispatchJob $job, User $provider): bool
    {
        return $provider->role === User::ROLE_THIRD_PARTY && $provider->thirdPartyProfile?->status === 'active';
    }

    public static function open(LabRequest $r): void
    {
        if ($r->status !== LabRequest::STATUS_REQUESTED || $r->third_party_profile_id) {
            return;
        }
        $r->loadMissing('patient');
        app(\App\Services\Dispatch\DispatchService::class)->open('lab_request', $r, [
            'patient_user_id' => $r->patient?->user_id,
            'lat' => $r->collection_lat,
            'lng' => $r->collection_lng,
            'capability' => 'lab',
            'expires_at' => now()->addHour(),
        ]);
    }
}
