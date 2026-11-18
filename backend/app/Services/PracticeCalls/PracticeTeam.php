<?php

namespace App\Services\PracticeCalls;

use App\Models\Clinic;
use App\Models\DoctorMatchRequest;
use App\Models\DoctorProfile;
use App\Models\PracticeStaff;
use App\Models\ProviderAvailability;
use App\Models\User;
use App\Services\Doctors\DoctorMatchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Who answers ORANGE requests for a practice, and who qualifies.
 *
 *  - A practice (clinic) qualifies when it has at least one available GP
 *    (active, accepting appointments, not switched OFFLINE, right specialty)
 *    and — when the subscriptions module is installed — an active platform
 *    subscription (PracticeSubscription::canReceiveRequests).
 *  - Its ONLINE reception team (role practice_reception, "Available now"
 *    toggle on, fresh heartbeat) gets one practice offer; when nobody is at
 *    reception, the practice's GPs who are online for on-demand requests
 *    get the offer themselves.
 *  - GPs without a practice get the offer when they are online.
 */
class PracticeTeam
{
    public const SUBSCRIPTION_GATE = 'App\\Services\\Billing\\PracticeSubscription';

    /** @return array<int, int> */
    public function clinicIdsFor(?User $user): array
    {
        return $user ? $user->practiceClinicIds() : [];
    }

    public function isMember(User $user, int $clinicId): bool
    {
        return in_array($clinicId, $user->practiceClinicIds(), true);
    }

    /** Active reception users of a clinic. @return Collection<int, User> */
    public function receptionists(int $clinicId): Collection
    {
        return User::query()
            ->where('role', User::ROLE_PRACTICE_RECEPTION)
            ->where('status', 'active')
            ->whereIn('id', PracticeStaff::query()->where('clinic_id', $clinicId)->where('is_active', true)->pluck('user_id'))
            ->get();
    }

    /** Reception users of a clinic who are online right now. @return Collection<int, User> */
    public function onlineReceptionists(int $clinicId): Collection
    {
        $users = $this->receptionists($clinicId);
        if ($users->isEmpty()) {
            return $users;
        }
        $rows = ProviderAvailability::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return $users->filter(fn (User $u) => (bool) $rows->get($u->id)?->isOnlineNow())->values();
    }

    /** Subscription gate from the subscriptions module (absent = everyone qualifies). */
    public static function canReceive(Clinic|DoctorProfile $subject): bool
    {
        $gate = self::SUBSCRIPTION_GATE;
        if (! class_exists($gate) || ! method_exists($gate, 'canReceiveRequests')) {
            return true;
        }
        try {
            return (bool) $gate::canReceiveRequests($subject);
        } catch (\Throwable $e) {
            Log::warning('[PracticeTeam] subscription gate failed: '.$e->getMessage());

            return true;
        }
    }

    /** GPs a practice could book for this request (bookable, not switched off). */
    public function availableGpsQuery(?string $specialty): \Illuminate\Database\Eloquent\Builder
    {
        return DoctorProfile::query()
            ->where('status', 'active')
            ->where('is_accepting_appointments', true)
            ->where('availability', '!=', DoctorProfile::OFFLINE)
            ->when($specialty !== null && $specialty !== '', fn ($q) => $q->where('specialization', $specialty));
    }

    /** Available GPs of one clinic, online / least recently assigned first. @return Collection<int, DoctorProfile> */
    public function clinicGps(int $clinicId, ?string $specialty): Collection
    {
        $dm = app(DoctorMatchService::class);

        return $this->availableGpsQuery($specialty)
            ->whereHas('clinics', fn ($q) => $q->where('clinics.id', $clinicId))
            ->with('user')
            ->get()
            ->sortBy(fn (DoctorProfile $d) => [$d->availability === DoctorProfile::AVAILABLE && $dm->isFresh($d) ? 0 : 1, $d->last_assigned_at?->getTimestamp() ?? 0, $d->id])
            ->values();
    }

    /**
     * Who gets the next round of alerts for a practice-flow request. Clinics / doctors
     * that already had an offer for this request are skipped (so a requeued request
     * goes to the OTHER practices).
     *
     * @return Collection<int, array{target: string, clinic: ?Clinic, doctor: DoctorProfile, recipients: array<int, int>}>
     */
    public function candidates(DoctorMatchRequest $req, int $limit = 60): Collection
    {
        // Skip practices / GPs that hold a live offer, declined, took and dropped it, or let it time out.
        // Offers closed early because ANOTHER practice accepted (responded before they expired) can be
        // offered again when the request is returned to the queue.
        $done = \App\Models\DoctorMatchOffer::query()->where('doctor_match_request_id', $req->id)->get()
            ->filter(fn ($o) => $o->status !== \App\Models\DoctorMatchOffer::EXPIRED
                || ! $o->responded_at || ! $o->expires_at || $o->responded_at->gte($o->expires_at->copy()->subSeconds(1)));
        $offeredClinics = $done->whereNotNull('clinic_id')->pluck('clinic_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $offeredDoctors = $done->pluck('doctor_profile_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $patientUserId = (int) ($req->patient?->user_id ?? 0);
        $dm = app(DoctorMatchService::class);

        $gps = $this->availableGpsQuery($req->specialty)
            ->when($patientUserId, fn ($q) => $q->where('user_id', '!=', $patientUserId))
            ->with(['clinics', 'user'])
            ->limit(500)
            ->get();

        $byClinic = [];
        $clinics = [];
        $solo = [];
        foreach ($gps as $doctor) {
            $docClinics = $doctor->clinics->filter(fn (Clinic $c) => ($c->status ?? 'active') !== 'inactive');
            if ($docClinics->isEmpty()) {
                $solo[] = $doctor;

                continue;
            }
            foreach ($docClinics as $c) {
                $byClinic[$c->id][] = $doctor;
                $clinics[$c->id] = $c;
            }
        }

        $out = new Collection;
        foreach ($byClinic as $clinicId => $doctors) {
            if (in_array((int) $clinicId, $offeredClinics, true)) {
                continue;
            }
            $clinic = $clinics[$clinicId];
            if (! self::canReceive($clinic)) {
                continue;
            }
            usort($doctors, fn ($a, $b) => [$a->availability === DoctorProfile::AVAILABLE && $dm->isFresh($a) ? 0 : 1, $a->last_assigned_at?->getTimestamp() ?? 0, $a->id]
                <=> [$b->availability === DoctorProfile::AVAILABLE && $dm->isFresh($b) ? 0 : 1, $b->last_assigned_at?->getTimestamp() ?? 0, $b->id]);
            $online = $this->onlineReceptionists((int) $clinicId);
            if ($online->isNotEmpty()) {
                $out->push(['target' => 'practice', 'clinic' => $clinic, 'doctor' => $doctors[0], 'recipients' => $online->pluck('id')->all()]);

                continue;
            }
            // Nobody at reception: the practice's GPs who are online for on-demand requests.
            foreach ($doctors as $d) {
                if (in_array((int) $d->id, $offeredDoctors, true) || $d->availability !== DoctorProfile::AVAILABLE || ! $dm->isFresh($d) || ! $d->user) {
                    continue;
                }
                $out->push(['target' => 'doctor', 'clinic' => $clinic, 'doctor' => $d, 'recipients' => [(int) $d->user_id]]);
                $offeredDoctors[] = (int) $d->id;
            }
        }
        foreach ($solo as $d) {
            if (in_array((int) $d->id, $offeredDoctors, true) || $d->availability !== DoctorProfile::AVAILABLE || ! $dm->isFresh($d) || ! $d->user || ! self::canReceive($d)) {
                continue;
            }
            $out->push(['target' => 'doctor', 'clinic' => null, 'doctor' => $d, 'recipients' => [(int) $d->user_id]]);
        }

        return $out->take(max(1, $limit))->values();
    }
}
