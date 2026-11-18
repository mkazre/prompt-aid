<?php

namespace App\Services\PracticeCalls;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Dispatch\ProviderAlerts;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Doctors\DoctorMatchService;
use App\Services\Notifications\ExpoPush;
use App\Services\Notifications\InAppNotifier;
use App\Services\Video\VideoRoomService;
use App\Support\PracticeCallSettings;
use App\Support\StaffNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * ORANGE practice call: after a practice accepted the request
 * (DoctorMatchService::acceptForPractice) an in-app VOICE call (Jitsi,
 * audio-only, same provider as video consults) rings BOTH the practice user
 * who accepted and the patient. Each side must accept; once both have,
 * the call is connected. Every call is a CallLog row (records for admin and
 * the practice); recordings are handled by CallRecordingService.
 *
 * Ringing: practice_call_ring_seconds per ring, re-rung automatically up to
 * practice_call_max_rings; then "missed". If the PRACTICE never picks up /
 * declines, the request goes back to the queue for the other practices after
 * practice_call_requeue_seconds (0 = never). If the PATIENT declines or misses
 * it, the practice can ring again.
 *
 * No queue worker: tick() runs lazily from every poll (and the Tick runner).
 */
class PracticeCallService
{
    public const RING_KEY = 'practice_call_ring:';

    public const EMBED_TTL = 300;

    public function __construct(protected VideoRoomService $video) {}

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    /** 'patient' | 'practice' | 'admin' | null */
    public function roleFor(CallLog $call, ?User $user): ?string
    {
        if (! $user || $user->status !== 'active') {
            return null;
        }
        if ($call->patient_user_id && (int) $call->patient_user_id === (int) $user->id) {
            return 'patient';
        }
        if ($call->clinic_id && in_array((int) $call->clinic_id, $user->practiceClinicIds(), true)) {
            return 'practice';
        }
        if ($user->isDoctor() && $user->doctorProfile && (int) $call->doctor_profile_id === (int) $user->doctorProfile->id) {
            return 'practice';
        }
        if ((int) $call->practice_user_id === (int) $user->id) {
            return 'practice';
        }
        if ($user->isSuperAdmin()) {
            return 'admin';
        }

        return null;
    }

    /** Call parties only (admins can read records, never join). */
    public function authorizeParty(CallLog $call, ?User $user): string
    {
        $role = $this->roleFor($call, $user);
        if (! in_array($role, ['patient', 'practice'], true)) {
            throw new DoctorMatchException('Call not found.', 404);
        }

        return $role;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function startForRequest(DoctorMatchRequest $req, User $practiceUser): CallLog
    {
        $req->loadMissing(['appointment', 'patient.user', 'doctor.user', 'clinic']);
        $call = CallLog::query()->create([
            'kind' => 'voice',
            'source' => CallLog::SOURCE_PRACTICE,
            'status' => CallLog::RINGING,
            'doctor_match_request_id' => $req->id,
            'appointment_id' => $req->appointment_id,
            'clinic_id' => $req->clinic_id,
            'doctor_profile_id' => $req->doctor_profile_id,
            'patient_profile_id' => $req->patient_profile_id,
            'patient_user_id' => $req->patient?->user_id,
            'practice_user_id' => $practiceUser->id,
            'started_by_user_id' => $practiceUser->id,
            'started_by_role' => 'practice',
            'room' => 'promptaid-pc-'.$req->id.'-'.bin2hex(random_bytes(8)),
            'ring_attempts' => 1,
            'ringing_at' => now(),
            'participants' => [$this->event($practiceUser, 'practice', 'ringing')],
            'meta' => [
                'record' => (bool) (((array) $req->scenario)['record_calls'] ?? true),
                'booking_modes' => ((array) $req->scenario)['booking']['modes'] ?? ['in_person', 'video'],
                'default_mode' => ((array) $req->scenario)['booking']['default_mode'] ?? 'in_person',
                'shuttle' => (bool) (((array) $req->scenario)['booking']['shuttle'] ?? true),
                'notice' => PracticeCallSettings::recordingNotice(),
            ],
        ]);
        $this->ringPatient($call, true);
        $this->ringPractice($call, true);

        return $call->fresh();
    }

    /** Practice rings the patient again (after a decline / missed call) — a new call attempt. */
    public function ringAgain(CallLog $call, User $user): CallLog
    {
        if ($this->authorizeParty($call, $user) !== 'practice') {
            throw new DoctorMatchException('Only the practice can call the patient.', 403);
        }
        $this->tick();
        $call->refresh();
        if ($call->isOpen()) {
            throw new DoctorMatchException('The call is still in progress.', 409);
        }
        $req = $call->matchRequest;
        if (! $req || $req->status !== DoctorMatchRequest::MATCHED || (int) $req->clinic_id !== (int) $call->clinic_id) {
            throw new DoctorMatchException('This patient is no longer with your practice.', 409);
        }
        if (CallLog::query()->where('doctor_match_request_id', $req->id)->whereIn('status', CallLog::OPEN)->exists()) {
            throw new DoctorMatchException('A call with this patient is already ringing.', 409);
        }
        $new = CallLog::query()->create(collect($call->getAttributes())->only([
            'kind', 'source', 'doctor_match_request_id', 'appointment_id', 'clinic_id', 'doctor_profile_id', 'patient_profile_id', 'patient_user_id',
        ])->all() + [
            'status' => CallLog::RINGING,
            'practice_user_id' => $user->id,
            'started_by_user_id' => $user->id,
            'started_by_role' => 'practice',
            'room' => 'promptaid-pc-'.$req->id.'-'.bin2hex(random_bytes(8)),
            'ring_attempts' => 1,
            'ringing_at' => now(),
            // The practice pressed "Call again": its side is accepted already.
            'practice_accepted_at' => now(),
            'participants' => [$this->event($user, 'practice', 'ringing'), $this->event($user, 'practice', 'accepted')],
            'meta' => array_merge((array) $call->meta, ['previous_call_id' => $call->id]),
        ]);
        $this->ringPatient($new, true);

        return $new->fresh();
    }

    public function accept(CallLog $call, User $user): CallLog
    {
        $role = $this->authorizeParty($call, $user);
        $this->tick();

        $result = DB::transaction(function () use ($call, $user, $role) {
            $c = CallLog::query()->whereKey($call->id)->lockForUpdate()->first();
            if (! $c || $c->status !== CallLog::RINGING) {
                return $c && $c->status === CallLog::CONNECTED ? $c : 'gone';
            }
            $now = now();
            $p = (array) $c->participants;
            if ($role === 'patient' && ! $c->patient_accepted_at) {
                $c->patient_accepted_at = $now;
                $p[] = $this->event($user, 'patient', 'accepted');
            } elseif ($role === 'practice' && ! $c->practice_accepted_at) {
                $c->practice_accepted_at = $now;
                $c->practice_user_id = $user->id;
                $p[] = $this->event($user, 'practice', 'accepted');
            }
            if ($c->patient_accepted_at && $c->practice_accepted_at) {
                $c->status = CallLog::CONNECTED;
                $c->connected_at = $now;
                $p[] = ['user_id' => null, 'role' => 'system', 'name' => 'Prompt Aid', 'event' => 'connected', 'at' => $now->toIso8601String()];
                $meta = (array) $c->meta;
                $meta['notice_shown_at'] = $now->toIso8601String();
                $c->meta = $meta;
            }
            $c->participants = $p;
            $c->save();

            return $c;
        });
        if ($result === 'gone') {
            throw new DoctorMatchException('This call has ended.', 409);
        }
        $this->clearRings($result);
        if ($result->status === CallLog::RINGING) {
            // Keep ringing the side that has not answered yet.
            $result->patient_accepted_at ? $this->ringPractice($result) : $this->ringPatient($result);
        } else {
            $this->bump($result);
        }

        return $result->fresh();
    }

    public function decline(CallLog $call, User $user, ?string $reason = null): CallLog
    {
        $role = $this->authorizeParty($call, $user);
        $done = DB::transaction(function () use ($call, $user, $role, $reason) {
            $c = CallLog::query()->whereKey($call->id)->lockForUpdate()->first();
            if (! $c || $c->status !== CallLog::RINGING) {
                return null;
            }
            $now = now();
            $p = (array) $c->participants;
            $p[] = $this->event($user, $role, 'declined');
            $c->forceFill([
                'status' => CallLog::DECLINED,
                'declined_by' => $role,
                'declined_by_user_id' => $user->id,
                'ended_at' => $now,
                'ended_by_user_id' => $user->id,
                'ended_by_role' => $role,
                'outcome' => $role === 'patient' ? 'patient_declined' : 'practice_declined',
                'end_reason' => $reason ? mb_substr($reason, 0, 255) : null,
                'participants' => $p,
            ])->save();

            return $c;
        });
        if (! $done) {
            return $call->fresh();
        }
        $this->clearRings($done);
        $this->bump($done);
        if ($role === 'practice') {
            // The practice cannot take the call: the patient goes back to the queue (other practices).
            if (PracticeCallSettings::int('practice_call_requeue_seconds') > 0) {
                $this->requeue($done, 'The practice declined the call');
            }
        } else {
            $this->tellPractice($done, 'Patient declined the call', ($done->patientUser?->name ?? 'The patient').' declined your call. You can call again from the call screen.');
        }

        return $done->fresh();
    }

    public function presence(CallLog $call, User $user, bool $in): CallLog
    {
        $role = $this->authorizeParty($call, $user);
        $key = 'pc-presence:'.$call->id.':'.$user->id;
        $last = Cache::get($key);
        if ($last === ($in ? 'in' : 'out')) {
            return $call; // heartbeat: only state changes are evidence
        }
        Cache::put($key, $in ? 'in' : 'out', 3600);
        DB::transaction(function () use ($call, $user, $role, $in) {
            $c = CallLog::query()->whereKey($call->id)->lockForUpdate()->first();
            $p = (array) $c->participants;
            $p[] = $this->event($user, $role, $in ? 'joined' : 'left');
            $c->participants = array_slice($p, -200);
            $c->save();
        });

        return $call->fresh();
    }

    /**
     * Hang up. $outcome defaults to booked / no_booking. $closeRequest (practice only,
     * no booking made) cancels the patient's request with "no booking".
     */
    public function end(CallLog $call, User $user, ?string $outcome = null, bool $closeRequest = false): CallLog
    {
        $role = $this->authorizeParty($call, $user);
        $ended = DB::transaction(function () use ($call, $user, $role, $outcome) {
            $c = CallLog::query()->whereKey($call->id)->lockForUpdate()->first();
            if (! $c || ! $c->isOpen()) {
                return null;
            }
            $now = now();
            $booked = $c->appointment && in_array($c->appointment->status, [Appointment::STATUS_CONFIRMED, Appointment::STATUS_PENDING], true);
            $p = (array) $c->participants;
            $p[] = $this->event($user, $role, 'ended');
            $wasConnected = $c->status === CallLog::CONNECTED;
            $c->forceFill([
                'status' => $wasConnected ? CallLog::ENDED : CallLog::CANCELLED,
                'ended_at' => $now,
                'ended_by_user_id' => $user->id,
                'ended_by_role' => $role,
                'duration_seconds' => $wasConnected && $c->connected_at ? max(0, $now->getTimestamp() - $c->connected_at->getTimestamp()) : 0,
                'outcome' => $outcome && isset(CallLog::OUTCOMES[$outcome]) ? $outcome : ($booked ? 'booked' : ($wasConnected ? 'no_booking' : 'cancelled')),
                'participants' => $p,
            ])->save();

            return $c;
        });
        if (! $ended) {
            return $call->fresh();
        }
        $this->clearRings($ended);
        $this->bump($ended);
        if ($role === 'patient') {
            $this->tellPractice($ended, 'Patient ended the call', ($ended->patientUser?->name ?? 'The patient').' hung up.');
        }
        if ($closeRequest && $role === 'practice') {
            $appt = $ended->appointment;
            if ($appt && $appt->status === Appointment::STATUS_MATCHING) {
                $appt->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => 'No booking was made during the practice call']);
            }
        }

        return $ended->fresh();
    }

    /** Patient shares live GPS from the call (used for the shuttle pickup). */
    public function shareLocation(CallLog $call, User $user, float $lat, float $lng, ?string $label = null): CallLog
    {
        if ($this->authorizeParty($call, $user) !== 'patient') {
            throw new DoctorMatchException('Only the patient can share their location.', 403);
        }
        if (abs($lat) > 90 || abs($lng) > 180) {
            throw new DoctorMatchException('Invalid location.', 422);
        }
        $meta = (array) $call->meta;
        $meta['patient_location'] = ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'label' => $label ? mb_substr($label, 0, 200) : 'My current location', 'at' => now()->toIso8601String()];
        $call->forceFill(['meta' => $meta])->save();
        $this->bump($call);

        return $call->fresh();
    }

    /** Ring timeouts, re-rings, missed calls and returning requests to the queue. */
    public function tick(): void
    {
        if (! Cache::add('practice_calls:tick', 1, 3)) {
            return;
        }
        $ringSecs = PracticeCallSettings::ringSeconds();
        $maxRings = PracticeCallSettings::maxRings();
        try {
            CallLog::query()->where('source', CallLog::SOURCE_PRACTICE)->where('status', CallLog::RINGING)
                ->where('ringing_at', '<=', now()->subSeconds($ringSecs))->orderBy('id')->limit(50)->get()
                ->each(function (CallLog $c) use ($maxRings) {
                    if ($c->ring_attempts < $maxRings) {
                        $c->forceFill(['ringing_at' => now(), 'ring_attempts' => $c->ring_attempts + 1])->save();
                        if (! $c->patient_accepted_at) {
                            $this->ringPatient($c, true);
                        }
                        if (! $c->practice_accepted_at) {
                            $this->ringPractice($c, true);
                        }

                        return;
                    }
                    $c->forceFill([
                        'status' => CallLog::MISSED,
                        'ended_at' => now(),
                        'outcome' => 'missed',
                        'end_reason' => ! $c->practice_accepted_at ? 'The practice did not answer' : 'The patient did not answer',
                    ])->save();
                    $this->clearRings($c);
                    $this->bump($c);
                    if ($c->practice_accepted_at) {
                        $this->tellPractice($c, 'Patient did not answer', 'You can call '.($c->patientUser?->name ?? 'the patient').' again from the call screen.');
                    }
                });

            // The practice never picked up: back to the queue for the other practices.
            $after = PracticeCallSettings::int('practice_call_requeue_seconds');
            if ($after > 0) {
                CallLog::query()->where('source', CallLog::SOURCE_PRACTICE)
                    ->whereNull('practice_accepted_at')->whereNull('connected_at')
                    ->whereIn('status', [CallLog::RINGING, CallLog::MISSED])
                    ->where('created_at', '<=', now()->subSeconds($after))
                    ->where('created_at', '>=', now()->subHours(6))
                    ->orderBy('id')->limit(20)->get()
                    ->each(fn (CallLog $c) => $this->requeue($c, 'The practice did not answer the call'));
            }
        } catch (\Throwable $e) {
            Log::warning('[PracticeCall] tick: '.$e->getMessage());
        }
    }

    /** Return the request to the queue so the OTHER practices are alerted (booking not yet made). */
    public function requeue(CallLog $call, string $reason): bool
    {
        $req = $call->matchRequest;
        if (! $req) {
            return false;
        }
        $done = DB::transaction(function () use ($call, $req, $reason) {
            $r = DoctorMatchRequest::query()->whereKey($req->id)->lockForUpdate()->first();
            $appt = $r?->appointment;
            if (! $r || $r->status !== DoctorMatchRequest::MATCHED || $r->flow !== 'practice' || ! $appt || $appt->status !== Appointment::STATUS_MATCHING) {
                return false;
            }
            if ((int) $r->clinic_id !== (int) $call->clinic_id) {
                return false;
            }
            $c = CallLog::query()->whereKey($call->id)->lockForUpdate()->first();
            $c->forceFill([
                'status' => $c->isOpen() ? CallLog::MISSED : $c->status,
                'ended_at' => $c->ended_at ?? now(),
                'outcome' => 'requeued',
                'end_reason' => mb_substr($reason, 0, 255),
            ])->save();
            DoctorMatchOffer::query()->where('doctor_match_request_id', $r->id)->where('status', DoctorMatchOffer::ACCEPTED)
                ->update(['status' => DoctorMatchOffer::DECLINED, 'updated_at' => now()]);
            $appt->update(['doctor_profile_id' => null, 'clinic_id' => null]);
            $r->update([
                'status' => DoctorMatchRequest::SEARCHING,
                'outcome_reason' => null,
                'matched_at' => null,
                'doctor_profile_id' => null,
                'clinic_id' => null,
                'expires_at' => now()->addMinutes(DoctorMatchService::requestTimeoutMinutes($r)),
            ]);

            return true;
        });
        if (! $done) {
            return false;
        }
        $call->refresh();
        $this->clearRings($call);
        $this->bump($call);
        try {
            app(DoctorMatchService::class)->progress($req->fresh());
        } catch (\Throwable $e) {
            Log::warning('[PracticeCall] requeue progress: '.$e->getMessage());
        }
        $patient = $call->patientUser;
        if ($patient) {
            app(InAppNotifier::class)->send($patient, 'appointment', 'Finding another practice', 'The practice could not take your call. We are asking the other available practices now.', [
                'route' => 'see_doctor_now', 'params' => ['request_id' => $req->id], 'url' => '/account/see-a-doctor',
            ]);
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Ringing
    // ------------------------------------------------------------------

    protected function ringPatient(CallLog $call, bool $push = false): void
    {
        $patient = $call->patientUser;
        if (! $patient) {
            return;
        }
        Cache::put(self::RING_KEY.$patient->id, $call->id, PracticeCallSettings::ringSeconds() + 15);
        if (! $push) {
            return;
        }
        $practice = $call->clinic?->name ?? ($call->doctor?->user ? 'Dr. '.$call->doctor->user->name : 'A practice');
        $title = $practice.' is calling you';
        $body = 'Tap to answer — they will book your visit on this call (in-app voice, uses data).';
        $data = [
            'type' => 'practice_call',
            'route' => 'practice_call',
            'call_id' => $call->id,
            'params' => ['call_id' => $call->id],
            'url' => $this->patientUrl($call),
        ];
        try {
            if (InAppNotifier::enabled('appointment')) {
                UserNotification::query()->create(['user_id' => $patient->id, 'type' => 'appointment', 'title' => $title, 'body' => $body, 'data' => $data]);
            }
        } catch (\Throwable $e) {
            Log::warning('[PracticeCall] feed row failed: '.$e->getMessage());
        }
        ExpoPush::toUsers($patient->id, $title, $body, $data, ProviderAlerts::PUSH_CHANNEL, ProviderAlerts::PUSH_SOUND);
    }

    protected function ringPractice(CallLog $call, bool $push = false): void
    {
        $uid = (int) $call->practice_user_id;
        if (! $uid) {
            return;
        }
        Cache::put(self::RING_KEY.'p:'.$uid, $call->id, PracticeCallSettings::ringSeconds() + 15);
        ProviderAlerts::bump($uid);
        if (! $push) {
            return;
        }
        $name = $call->patientUser?->name ?? 'The patient';
        $data = ['type' => 'practice_call', 'route' => 'practice_call', 'call_id' => $call->id, 'params' => ['call_id' => $call->id], 'url' => $this->practiceUrl($call)];
        ExpoPush::toUsers($uid, 'Call with '.$name, 'Tap to answer and book the visit.', $data, ProviderAlerts::PUSH_CHANNEL, ProviderAlerts::PUSH_SOUND);
    }

    protected function clearRings(CallLog $call): void
    {
        foreach ([self::RING_KEY.$call->patient_user_id, self::RING_KEY.'p:'.$call->practice_user_id] as $k) {
            if ((int) Cache::get($k) === (int) $call->id) {
                Cache::forget($k);
            }
        }
    }

    protected function bump(CallLog $call): void
    {
        if ($call->practice_user_id) {
            ProviderAlerts::bump((int) $call->practice_user_id);
        }
        Cache::put('pc-version:'.$call->id, (string) microtime(true), 3600);
    }

    protected function tellPractice(CallLog $call, string $title, string $body): void
    {
        $u = $call->practiceUser;
        if (! $u) {
            return;
        }
        try {
            StaffNotifier::alert($u, $title, $body, icon: 'heroicon-o-phone', color: 'warning', url: $this->practiceUrl($call), actionLabel: 'Open call');
        } catch (\Throwable) {
        }
        app(InAppNotifier::class)->send($u, 'appointment', $title, $body, ['route' => 'practice_call', 'params' => ['call_id' => $call->id]]);
    }

    /** Patient poll (app + website, cache-only until something rings). @return array<string, mixed>|null */
    public function ringFor(User $user): ?array
    {
        $this->tick();
        $id = Cache::get(self::RING_KEY.$user->id);
        if (! $id) {
            return null;
        }
        $call = CallLog::query()->find((int) $id);
        if (! $call || (int) $call->patient_user_id !== (int) $user->id || $call->status !== CallLog::RINGING || $call->patient_accepted_at) {
            Cache::forget(self::RING_KEY.$user->id);

            return null;
        }

        return $this->ringPayload($call, 'patient');
    }

    /** Practice user poll. @return array<string, mixed>|null */
    public function practiceRingFor(User $user): ?array
    {
        $this->tick();
        $id = Cache::get(self::RING_KEY.'p:'.$user->id);
        if (! $id) {
            return null;
        }
        $call = CallLog::query()->find((int) $id);
        if (! $call || (int) $call->practice_user_id !== (int) $user->id || $call->status !== CallLog::RINGING || $call->practice_accepted_at) {
            Cache::forget(self::RING_KEY.'p:'.$user->id);

            return null;
        }

        return $this->ringPayload($call, 'practice');
    }

    /** @return array<string, mixed> */
    public function ringPayload(CallLog $call, string $side): array
    {
        $call->loadMissing(['clinic', 'doctor.user', 'patientUser']);
        $ends = ($call->ringing_at ?? now())->copy()->addSeconds(PracticeCallSettings::ringSeconds());

        return [
            'call_id' => $call->id,
            'uuid' => $call->uuid,
            'kind' => 'practice_call',
            'channel' => 'voice',
            'side' => $side,
            'caller_name' => $side === 'patient' ? ($call->clinic?->name ?? ($call->doctor?->user ? 'Dr. '.$call->doctor->user->name : 'Your practice')) : ($call->patientUser?->name ?? 'Patient'),
            'caller_sub' => $side === 'patient' ? ($call->doctor?->user ? 'For Dr. '.$call->doctor->user->name : null) : 'Urgent (orange) patient — book the visit on this call',
            'recorded' => $this->recordingOn($call),
            'notice' => $this->recordingOn($call) ? PracticeCallSettings::recordingNotice() : null,
            'started_at' => $call->ringing_at?->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($ends, false)),
            'url' => $side === 'patient' ? $this->patientUrl($call) : $this->practiceUrl($call),
        ];
    }

    // ------------------------------------------------------------------
    // Payloads
    // ------------------------------------------------------------------

    public function recordingOn(CallLog $call): bool
    {
        return PracticeCallSettings::bool('call_recording_enabled')
            && PracticeCallSettings::str('call_recording_mode') !== 'off'
            && (bool) (((array) $call->meta)['record'] ?? true);
    }

    public function patientUrl(CallLog $call): string
    {
        return Route::has('account.practice-call') ? route('account.practice-call', $call->id, false) : '/account/practice-calls/'.$call->id;
    }

    public function practiceUrl(CallLog $call): string
    {
        return class_exists(\App\Filament\Pages\PracticeCallPage::class)
            ? \App\Filament\Pages\PracticeCallPage::getUrl(['call' => $call->id], panel: 'admin')
            : '/staff';
    }

    /**
     * Full state for a party ('web' | 'staff' | 'app').
     *
     * @return array<string, mixed>
     */
    public function payload(CallLog $call, User $user, string $mode = 'web'): array
    {
        $this->tick();
        $call->refresh()->loadMissing(['clinic', 'doctor.user', 'patientUser', 'patient', 'appointment', 'practiceUser', 'recordings']);
        $role = $this->authorizeParty($call, $user);
        $booking = app(PracticeBookingService::class);
        $appt = $call->appointment;
        $needsMyAccept = $call->status === CallLog::RINGING && ($role === 'patient' ? ! $call->patient_accepted_at : ! $call->practice_accepted_at);
        $meta = (array) $call->meta;
        $req = $call->matchRequest;
        $canRingAgain = $role === 'practice' && ! $call->isOpen() && $req && $req->status === DoctorMatchRequest::MATCHED
            && (int) $req->clinic_id === (int) $call->clinic_id
            && ! CallLog::query()->where('doctor_match_request_id', $req->id)->where('id', '>', $call->id)->exists();
        $latest = $req ? CallLog::query()->where('doctor_match_request_id', $req->id)->latest('id')->value('id') : null;

        return [
            'id' => $call->id,
            'uuid' => $call->uuid,
            'role' => $role,
            'status' => $call->status,
            'status_text' => $this->statusText($call, $role),
            'kind' => $call->kind,
            'needs_my_accept' => $needsMyAccept,
            'practice_accepted' => (bool) $call->practice_accepted_at,
            'patient_accepted' => (bool) $call->patient_accepted_at,
            'connected' => $call->status === CallLog::CONNECTED,
            'can_ring_again' => $canRingAgain,
            'newer_call_id' => $latest && (int) $latest !== (int) $call->id ? (int) $latest : null,
            'ring_seconds_left' => $call->status === CallLog::RINGING ? max(0, (int) now()->diffInSeconds(($call->ringing_at ?? now())->copy()->addSeconds(PracticeCallSettings::ringSeconds()), false)) : 0,
            'ringing_at' => $call->ringing_at?->toIso8601String(),
            'connected_at' => $call->connected_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => (int) $call->duration_seconds,
            'outcome' => $call->outcome,
            'outcome_label' => CallLog::outcomeLabel($call->outcome),
            'server_time' => now()->toIso8601String(),
            'recorded' => $this->recordingOn($call),
            'notice' => $this->recordingOn($call) ? PracticeCallSettings::recordingNotice() : null,
            'recording' => $role === 'practice' ? app(CallRecordingService::class)->clientPlan($call) : null,
            'practice' => [
                'name' => $call->clinic?->name ?? 'Practice',
                'address' => $call->clinic ? trim(implode(', ', array_filter([$call->clinic->address, $call->clinic->city]))) : null,
                'phone' => $call->clinic?->phone,
                'user_name' => $call->practiceUser?->name,
            ],
            'patient' => [
                'name' => $call->patientUser?->name ?? 'Patient',
                'phone' => $role === 'practice' ? $call->patientUser?->phone : null,
                'brief' => $role === 'practice' ? $this->patientBrief($call) : null,
                'location' => $role === 'practice' || $role === 'patient' ? ($meta['patient_location'] ?? null) : null,
            ],
            'doctor' => $call->doctor ? ['id' => $call->doctor->id, 'name' => 'Dr. '.($call->doctor->user?->name ?? ''), 'specialization' => $call->doctor->specialization] : null,
            'booking' => $booking->bookingPayload($call, $role),
            'booking_options' => $role === 'practice' ? $booking->options($call) : null,
            'shuttle' => $booking->shuttlePayload($call, $role),
            'join' => in_array($call->status, CallLog::OPEN, true) && ! $needsMyAccept ? $this->joinInfo($call, $user, $role, $mode) : null,
            'urls' => [
                'web' => $role === 'patient' ? $this->patientUrl($call) : $this->practiceUrl($call),
            ],
        ];
    }

    protected function statusText(CallLog $call, string $role): string
    {
        $other = $role === 'patient' ? ($call->clinic?->name ?? 'the practice') : ($call->patientUser?->name ?? 'the patient');

        return match ($call->status) {
            CallLog::RINGING => ($role === 'patient' ? $call->patient_accepted_at : $call->practice_accepted_at)
                ? 'Waiting for '.$other.' to answer…'
                : 'Incoming call from '.$other,
            CallLog::CONNECTED => 'Connected with '.$other,
            CallLog::DECLINED => $call->declined_by === $role ? 'You declined the call' : ucfirst($other).' declined the call',
            CallLog::MISSED => $call->outcome === 'requeued' ? 'Returned to the queue — another practice will be asked' : 'Missed call',
            CallLog::CANCELLED => 'Call cancelled',
            default => 'Call ended'.($call->duration_seconds ? ' · '.gmdate($call->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $call->duration_seconds) : ''),
        };
    }

    /** @return array<int, string> */
    protected function patientBrief(CallLog $call): array
    {
        try {
            $req = $call->matchRequest;
            $calls = app(\App\Services\Consults\ConsultCallService::class);

            return $req ? $calls->alertLines($call->patient, $req->triageSubmission, $call->appointment, 'video') : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Jitsi join info: web/staff = page config; app = single-use embed URL. @return array<string, mixed>|null */
    public function joinInfo(CallLog $call, User $user, string $role, string $mode): ?array
    {
        if (! $call->room) {
            return null;
        }
        $counterpart = $role === 'patient' ? ($call->clinic?->name ?? 'Your practice') : ($call->patientUser?->name ?? 'Patient');
        $jaasRecord = $role === 'practice' && $this->recordingOn($call) && app(CallRecordingService::class)->usesJaas();
        try {
            if ($mode === 'app') {
                $token = Str::random(48);
                Cache::put('pc-embed:'.hash('sha256', $token), ['call_id' => $call->id, 'user_id' => $user->id], self::EMBED_TTL);

                return [
                    'embed_url' => route('practice-call.embed', ['call' => $call->id, 't' => $token]),
                    'expires_at' => now()->addSeconds(self::EMBED_TTL)->toIso8601String(),
                    'provider' => $this->video->provider(),
                    'counterpart' => $counterpart,
                ];
            }

            return ['config' => $this->callConfig($call, $user, $role, $mode === 'staff' ? 'staff' : 'web', $counterpart, $jaasRecord)];
        } catch (\Throwable $e) {
            Log::warning('[PracticeCall] join config: '.$e->getMessage());

            return ['error' => 'In-app calls are temporarily unavailable. Please try again shortly.'];
        }
    }

    /** @return array<string, mixed> */
    public function callConfig(CallLog $call, User $user, string $role, string $mode, string $counterpart, bool $jaasRecord): array
    {
        return $this->video->roomConfig((string) $call->room, $user, $role === 'practice', true, $mode, $counterpart, $jaasRecord, [
            'practiceCall' => true,
            'callId' => $call->id,
            'autoRecord' => $jaasRecord, // the practice page starts JaaS file recording once both are in
            'timerFrom' => $call->connected_at?->toIso8601String(),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    /** @return array{call_id: int, user_id: int}|null */
    public function consumeEmbedToken(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }
        $data = Cache::pull('pc-embed:'.hash('sha256', $token));

        return is_array($data) ? $data : null;
    }

    /**
     * Compact block for the patient's "see a doctor now" payload (request flow = practice).
     *
     * @return array<string, mixed>|null
     */
    public function requestSummary(DoctorMatchRequest $req): ?array
    {
        $call = CallLog::query()->where('doctor_match_request_id', $req->id)->latest('id')->first();
        $appt = $req->appointment;
        $booked = $appt && in_array($appt->status, [Appointment::STATUS_CONFIRMED, Appointment::STATUS_PENDING, Appointment::STATUS_CHECKED_IN], true) && $req->status === DoctorMatchRequest::MATCHED;

        return [
            'call_id' => $call?->id,
            'call_status' => $call?->status,
            'ringing' => $call?->status === CallLog::RINGING && ! $call->patient_accepted_at,
            'url' => $call ? $this->patientUrl($call) : null,
            'practice_name' => $req->clinic?->name,
            'booked' => $booked,
            'booking' => $booked ? app(PracticeBookingService::class)->appointmentCard($appt) : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function event(User $user, string $role, string $event): array
    {
        return ['user_id' => $user->id, 'role' => $role, 'name' => $role === 'practice' && $user->isDoctor() ? 'Dr. '.$user->name : $user->name, 'event' => $event, 'at' => now()->toIso8601String()];
    }

    public static function fmtDuration(int $seconds): string
    {
        return $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds);
    }

    public static function local(?Carbon $t): ?string
    {
        return $t?->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->format('D j M Y H:i:s');
    }
}
