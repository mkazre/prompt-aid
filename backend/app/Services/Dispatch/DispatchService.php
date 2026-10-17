<?php

namespace App\Services\Dispatch;

use App\Models\DispatchJob;
use App\Models\DispatchOffer;
use App\Models\User;
use App\Services\Dispatch\Handlers\AppointmentHandler;
use App\Services\Dispatch\Handlers\DispatchHandler;
use App\Services\Dispatch\Handlers\DoctorLaterHandler;
use App\Services\Dispatch\Handlers\LabRequestHandler;
use App\Services\Dispatch\Handlers\PharmacyOrderHandler;
use App\Services\Dispatch\Handlers\ScriptReviewHandler;
use App\Services\Notifications\InAppNotifier;
use App\Support\DispatchSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unified broadcast dispatch engine for the generic service types
 * (appointment, doctor_later, pharmacy_order, script_review, lab_request).
 * Shuttle rides and "see a doctor now" use the same semantics inside
 * RideDispatchService / DoctorMatchService (their own offer tables).
 *
 * Modes (DispatchSettings::mode):
 *   broadcast  — every qualifying provider gets an offer at once; the FIRST
 *                accept wins atomically (row lock + conditional UPDATE);
 *                every other pending offer flips to "taken" and their
 *                popups say "Accepted by another provider".
 *   sequential — one provider at a time, next on decline/timeout.
 *   manual     — no automatic offers; an admin assigns from Live requests.
 *
 * No queue worker on shared hosting: progression is lazy (provider inbox
 * polls, patient status polls) plus the Tick runner every 15 s.
 */
class DispatchService
{
    /** @var array<string, class-string<DispatchHandler>> */
    public const HANDLERS = [
        'appointment' => AppointmentHandler::class,
        'doctor_later' => DoctorLaterHandler::class,
        'pharmacy_order' => PharmacyOrderHandler::class,
        'script_review' => ScriptReviewHandler::class,
        'lab_request' => LabRequestHandler::class,
    ];

    public function handler(string $service): DispatchHandler
    {
        $class = self::HANDLERS[$service] ?? null;
        if (! $class) {
            throw new DispatchException('Unknown service '.$service);
        }

        return app($class);
    }

    public function jobFor(Model $subject, ?string $service = null): ?DispatchJob
    {
        return DispatchJob::query()
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->when($service, fn ($q) => $q->where('service', $service))
            ->latest('id')->first();
    }

    /**
     * Start dispatching a subject (idempotent: an open job for the same
     * subject + service is reused).
     *
     * @param  array<string, mixed>  $attrs  lat, lng, radius_km, capability, window_from, window_to, target_user_id, patient_user_id, expires_at, meta
     */
    public function open(string $service, Model $subject, array $attrs = []): DispatchJob
    {
        $existing = DispatchJob::query()
            ->where('service', $service)->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->whereIn('status', DispatchJob::OPEN)->first();
        if ($existing) {
            return $existing;
        }

        $mode = DispatchSettings::mode($service);
        $job = DispatchJob::query()->create(array_merge([
            'service' => $service,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'mode' => $mode,
            'status' => $mode === 'manual' ? DispatchJob::MANUAL : DispatchJob::SEARCHING,
            'expires_at' => now()->addMinutes(max(1, DispatchSettings::int('dispatch_request_expiry_minutes'))),
        ], $attrs));

        if ($mode === 'manual') {
            $this->alertAdmins($job, 'Request waiting for manual assignment');
        } else {
            $this->pump($job);
        }

        return $job->fresh();
    }

    /** Create offers per mode. Cheap no-op when nothing changed. */
    public function pump(DispatchJob $job): void
    {
        if ($job->status !== DispatchJob::SEARCHING) {
            return;
        }
        $lock = Cache::lock('dispatch:job:'.$job->id, 15);
        if (! $lock->get()) {
            return;
        }

        try {
            $job->refresh();
            if ($job->status !== DispatchJob::SEARCHING) {
                return;
            }
            $handler = $this->handler($job->service);
            if (! $handler->stillOpen($job)) {
                $this->close($job, DispatchJob::CANCELLED, 'handled_elsewhere');

                return;
            }
            if ($job->expires_at && $job->expires_at->isPast()) {
                $this->expire($job);

                return;
            }

            $this->expireOffers($job);
            $already = DispatchOffer::query()->where('dispatch_job_id', $job->id)->pluck('provider_user_id')->all();
            $livePending = DispatchOffer::query()->where('dispatch_job_id', $job->id)->where('status', DispatchOffer::PENDING)->exists();

            if ($job->mode === 'sequential' && $livePending) {
                return;
            }

            $candidates = $handler->candidates($job)->reject(fn ($c) => in_array($c['user']->id, $already, true))->values();
            if ($candidates->isEmpty()) {
                return;
            }
            if ($job->mode === 'sequential') {
                $candidates = $candidates->take(1);
            }

            $ttl = $handler->offerTtl($job);
            $card = $handler->card($job);
            foreach ($candidates->take(60) as $c) {
                $offer = DispatchOffer::query()->create([
                    'dispatch_job_id' => $job->id,
                    'provider_user_id' => $c['user']->id,
                    'status' => DispatchOffer::PENDING,
                    'distance_km' => $c['distance_km'],
                    'offered_at' => now(),
                    'expires_at' => now()->addSeconds($ttl),
                ]);
                ProviderAlerts::offer($c['user'], $card['title'], $card['subtitle'], [
                    'service' => $job->service, 'request_id' => $job->id, 'offer_id' => $offer->id, 'offer_key' => 'job:'.$offer->id,
                    'url' => $card['url'],
                ]);
            }
            $job->increment('offers_count', $candidates->count());
        } catch (\Throwable $e) {
            Log::error('[Dispatch] pump failed for job '.$job->id.': '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /** A provider just came online / set a window: offer them every open job they qualify for. */
    public function offerOpenJobsTo(User $user): void
    {
        try {
            DispatchJob::query()->where('status', DispatchJob::SEARCHING)->where('expires_at', '>', now())
                ->orderBy('id')->limit(50)->get()->each(fn (DispatchJob $j) => $this->pump($j));
        } catch (\Throwable $e) {
            Log::warning('[Dispatch] offerOpenJobsTo failed: '.$e->getMessage());
        }
    }

    public function accept(DispatchOffer $offer, User $user): DispatchJob
    {
        if ((int) $offer->provider_user_id !== (int) $user->id) {
            throw new DispatchException('This request was not offered to you.', 403);
        }

        $result = DB::transaction(function () use ($offer, $user) {
            $job = DispatchJob::query()->whereKey($offer->dispatch_job_id)->lockForUpdate()->first();
            $offer = DispatchOffer::query()->whereKey($offer->id)->lockForUpdate()->first();
            if (! $job || ! $offer) {
                return 'gone';
            }
            if ($offer->status === DispatchOffer::TAKEN || ($job->accepted_by_user_id && (int) $job->accepted_by_user_id !== (int) $user->id)) {
                return 'taken';
            }
            if ($offer->status !== DispatchOffer::PENDING || ! $job->isOpen()) {
                return 'gone';
            }
            // 3 s grace for a countdown that hit zero mid-request.
            if ($offer->expires_at && $offer->expires_at->lt(now()->subSeconds(3))) {
                $offer->update(['status' => DispatchOffer::EXPIRED, 'responded_at' => now()]);

                return 'expired';
            }

            // First accept wins: conditional update is the race guard.
            $won = DispatchJob::query()->whereKey($job->id)->whereIn('status', DispatchJob::OPEN)->whereNull('accepted_by_user_id')
                ->update(['status' => DispatchJob::ACCEPTED, 'accepted_by_user_id' => $user->id, 'accepted_at' => now(), 'updated_at' => now()]);
            if (! $won) {
                return 'taken';
            }
            $job->refresh();
            $offer->update(['status' => DispatchOffer::ACCEPTED, 'responded_at' => now()]);
            DispatchOffer::query()->where('dispatch_job_id', $job->id)->where('id', '!=', $offer->id)->where('status', DispatchOffer::PENDING)
                ->update(['status' => DispatchOffer::TAKEN, 'responded_at' => now(), 'updated_at' => now()]);

            $this->handler($job->service)->onAccepted($job, $user, false);

            return $job;
        });

        if ($result === 'taken') {
            $offer->refresh();
            if ($offer->status === DispatchOffer::PENDING) {
                $offer->update(['status' => DispatchOffer::TAKEN, 'responded_at' => now()]);
            }
            throw new DispatchException('Accepted by another provider — no longer available.', 409);
        }
        if ($result === 'expired') {
            throw new DispatchException('This request has expired.', 409);
        }
        if ($result === 'gone') {
            throw new DispatchException('This request is no longer available.', 409);
        }

        $this->bumpOffered($result);
        try {
            $this->handler($result->service)->afterAccepted($result, $user, false);
        } catch (\Throwable $e) {
            Log::warning('[Dispatch] afterAccepted failed: '.$e->getMessage());
        }

        return $result;
    }

    public function decline(DispatchOffer $offer, User $user, ?string $reason = null): void
    {
        if ((int) $offer->provider_user_id !== (int) $user->id) {
            throw new DispatchException('This request was not offered to you.', 403);
        }
        $updated = DispatchOffer::query()->whereKey($offer->id)->where('status', DispatchOffer::PENDING)
            ->update(['status' => DispatchOffer::DECLINED, 'responded_at' => now(), 'decline_reason' => $reason ? mb_substr($reason, 0, 255) : null, 'updated_at' => now()]);
        ProviderAlerts::bump($user->id);
        if (! $updated) {
            return;
        }
        $job = $offer->job()->first();
        if (! $job) {
            return;
        }
        try {
            $this->handler($job->service)->onDeclined($job, $user, $reason);
        } catch (\Throwable $e) {
            Log::warning('[Dispatch] onDeclined failed: '.$e->getMessage());
        }
        $this->pump($job->fresh());
    }

    /** Admin assigns (any mode). The provider gets an "assigned to you" alert. */
    public function assignManually(DispatchJob $job, User $provider, ?User $admin = null): DispatchJob
    {
        $handler = $this->handler($job->service);
        if (! $handler->canServe($job, $provider)) {
            throw new DispatchException('That provider cannot serve this request.', 422);
        }

        $job = DB::transaction(function () use ($job, $provider, $handler) {
            $job = DispatchJob::query()->whereKey($job->id)->lockForUpdate()->first();
            if (! $job || ! $job->isOpen()) {
                throw new DispatchException('This request is no longer open.', 409);
            }
            $job->update(['status' => DispatchJob::ACCEPTED, 'accepted_by_user_id' => $provider->id, 'accepted_at' => now(), 'closed_reason' => 'manual_assign']);
            DispatchOffer::query()->where('dispatch_job_id', $job->id)->where('status', DispatchOffer::PENDING)
                ->update(['status' => DispatchOffer::CANCELLED, 'responded_at' => now(), 'updated_at' => now()]);
            DispatchOffer::query()->create([
                'dispatch_job_id' => $job->id, 'provider_user_id' => $provider->id, 'status' => DispatchOffer::ACCEPTED,
                'offered_at' => now(), 'expires_at' => now(), 'responded_at' => now(),
            ]);
            $handler->onAccepted($job, $provider, true);

            return $job->fresh();
        });

        $this->bumpOffered($job);
        $card = $handler->card($job);
        ProviderAlerts::assigned($provider, 'Assigned to you: '.$card['title'], $card['subtitle'].' — assigned by Prompt Aid operations.', [
            'service' => $job->service, 'request_id' => $job->id, 'url' => $card['url'],
        ]);
        try {
            $handler->afterAccepted($job, $provider, true);
        } catch (\Throwable $e) {
            Log::warning('[Dispatch] afterAccepted (manual) failed: '.$e->getMessage());
        }

        return $job;
    }

    /** Patient/admin cancelled the subject, or it was handled elsewhere. */
    public function cancelFor(Model $subject, string $reason = 'cancelled'): void
    {
        DispatchJob::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->whereIn('status', DispatchJob::OPEN)->get()
            ->each(fn (DispatchJob $j) => $this->close($j, DispatchJob::CANCELLED, $reason));
    }

    public function close(DispatchJob $job, string $status, string $reason): void
    {
        $n = DispatchJob::query()->whereKey($job->id)->whereIn('status', DispatchJob::OPEN)
            ->update(['status' => $status, 'closed_reason' => $reason, 'updated_at' => now()]);
        if (! $n) {
            return;
        }
        DispatchOffer::query()->where('dispatch_job_id', $job->id)->where('status', DispatchOffer::PENDING)
            ->update(['status' => DispatchOffer::CANCELLED, 'responded_at' => now(), 'updated_at' => now()]);
        $this->bumpOffered($job);
    }

    public function expire(DispatchJob $job): void
    {
        $n = DispatchJob::query()->whereKey($job->id)->whereIn('status', DispatchJob::OPEN)
            ->update(['status' => DispatchJob::EXPIRED, 'closed_reason' => 'no_provider_accepted', 'updated_at' => now()]);
        if (! $n) {
            return;
        }
        DispatchOffer::query()->where('dispatch_job_id', $job->id)->where('status', DispatchOffer::PENDING)
            ->update(['status' => DispatchOffer::EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);
        $this->bumpOffered($job);
        try {
            $this->handler($job->service)->onExpired($job->fresh());
        } catch (\Throwable $e) {
            Log::warning('[Dispatch] onExpired failed: '.$e->getMessage());
        }
    }

    /** Patient "try again" (optionally with a wider radius). */
    public function retry(DispatchJob $job, ?float $radiusKm = null): DispatchJob
    {
        if (! in_array($job->status, [DispatchJob::EXPIRED], true)) {
            throw new DispatchException('This request is not waiting for a retry.', 409);
        }
        $job->update([
            'status' => DispatchSettings::mode($job->service) === 'manual' ? DispatchJob::MANUAL : DispatchJob::SEARCHING,
            'mode' => DispatchSettings::mode($job->service),
            'closed_reason' => null,
            'radius_km' => $radiusKm !== null ? DispatchSettings::clampRadius($radiusKm) : $job->radius_km,
            'expires_at' => now()->addMinutes(max(1, DispatchSettings::int('dispatch_request_expiry_minutes'))),
        ]);
        // Previously offered providers may be offered again.
        DispatchOffer::query()->where('dispatch_job_id', $job->id)->whereIn('status', [DispatchOffer::EXPIRED, DispatchOffer::CANCELLED])->delete();
        $this->pump($job);

        return $job->fresh();
    }

    /** Tick: expire lapsed offers, progress/expire jobs. Throttled. */
    public function tick(bool $force = false): int
    {
        if (! $force && ! Cache::add('dispatch:tick', 1, 5)) {
            return 0;
        }
        $n = 0;
        try {
            $lapsed = DispatchOffer::query()->where('status', DispatchOffer::PENDING)->where('expires_at', '<=', now())->get(['id', 'provider_user_id']);
            if ($lapsed->isNotEmpty()) {
                DispatchOffer::query()->whereIn('id', $lapsed->pluck('id'))->update(['status' => DispatchOffer::EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);
                $lapsed->pluck('provider_user_id')->unique()->each(fn ($id) => ProviderAlerts::bump((int) $id));
            }

            DispatchJob::query()->where('status', DispatchJob::SEARCHING)->orderBy('id')->limit(100)->get()
                ->each(function (DispatchJob $job) use (&$n) {
                    $n++;
                    if ($job->expires_at && $job->expires_at->isPast()) {
                        $this->expire($job);
                    } else {
                        $this->pump($job);
                    }
                });

            // Manual jobs past their expiry stay visible to admins, but close if the subject was handled.
            DispatchJob::query()->where('status', DispatchJob::MANUAL)->orderBy('id')->limit(100)->get()
                ->each(function (DispatchJob $job) {
                    if (! $this->handler($job->service)->stillOpen($job)) {
                        $this->close($job, DispatchJob::CANCELLED, 'handled_elsewhere');
                    }
                });
        } catch (\Throwable $e) {
            Log::error('[Dispatch] tick failed: '.$e->getMessage());
        }

        return $n;
    }

    protected function expireOffers(DispatchJob $job): void
    {
        DispatchOffer::query()->where('dispatch_job_id', $job->id)->where('status', DispatchOffer::PENDING)->where('expires_at', '<=', now())
            ->update(['status' => DispatchOffer::EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);
    }

    protected function bumpOffered(DispatchJob $job): void
    {
        DispatchOffer::query()->where('dispatch_job_id', $job->id)->pluck('provider_user_id')->unique()
            ->each(fn ($id) => ProviderAlerts::bump((int) $id));
    }

    public function alertAdmins(DispatchJob $job, string $title): void
    {
        try {
            $card = $this->handler($job->service)->card($job);
            $notifier = app(InAppNotifier::class);
            User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->pluck('id')
                ->each(fn ($id) => $notifier->send((int) $id, 'general', $title.': '.$card['title'], $card['subtitle'], ['url' => '/staff/live-requests']));
        } catch (\Throwable $e) {
            Log::warning('[Dispatch] admin alert failed: '.$e->getMessage());
        }
    }
}
