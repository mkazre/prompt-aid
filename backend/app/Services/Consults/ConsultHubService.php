<?php

namespace App\Services\Consults;

use App\Events\ConsultEnded;
use App\Events\ConsultStarted;
use App\Models\Appointment;
use App\Models\ConsultSession;
use App\Models\Conversation;
use App\Models\DoctorMatchRequest;
use App\Models\EmergencyContact;
use App\Models\Message;
use App\Models\TriageSubmission;
use App\Models\User;
use App\Services\Comms\ChatService;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Notifications\InAppNotifier;
use App\Support\StaffNotifier;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Consult hub — one place for both sides once a doctor has the consult
 * (orange instant consults and booked video / call-back appointments).
 *
 * Channels, in-app first: video (default) → in-app voice (Jitsi audio-only,
 * same room) → live chat; phone / WhatsApp only as fallback. Switching
 * channel keeps the same ConsultSession, so the consult timer and the
 * billing record carry on.
 *
 * Connection evidence (fires App\Events\ConsultStarted exactly once):
 *  - video / voice: BOTH participants report "in the call" (presence
 *    heartbeats from their own client) within PRESENCE_FRESH_SECONDS;
 *  - chat: the doctor's first message in the consult's thread while the
 *    hub session is open;
 *  - phone / WhatsApp: the doctor marks "patient answered".
 * End (App\Events\ConsultEnded, exactly once): the doctor ends with an
 * outcome; legacy call-back outcomes and status changes elsewhere close it too.
 *
 * Every public method that takes a User checks that they are this
 * appointment's own patient / doctor (DoctorMatchException 404 otherwise —
 * admins included, so nobody silently joins a consult).
 */
class ConsultHubService
{
    public const ACTIVE = [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN];

    public const CALL_CHANNELS = ['video', 'voice'];

    public const FALLBACK_CHANNELS = ['phone', 'whatsapp'];

    /** A participant counts as "in the call" this long after their last heartbeat (clients beat every 15–20 s). */
    public const PRESENCE_FRESH_SECONDS = 45;

    /** A call segment with no heartbeat for this long is closed and a new one opened. */
    public const SEGMENT_GAP_SECONDS = 75;

    /** ConsultEnded outcome strings Payments acts on (see App\Events\ConsultEnded). */
    public const EVENT_OUTCOME = [
        'completed' => 'completed',
        'escalated' => 'completed',
        'patient_no_show' => 'patient_no_show',
        'no_answer' => 'no_answer',
        'failed' => 'failed',
        'doctor_cancelled' => 'doctor_cancelled',
    ];

    public function __construct(protected ConsultCallService $calls) {}

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function roleFor(Appointment $appt, ?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        if ($user->isPatient() && $user->patientProfile && (int) $appt->patient_profile_id === (int) $user->patientProfile->id) {
            return 'patient';
        }
        if ($user->isDoctor() && $user->doctorProfile && $appt->doctor_profile_id && (int) $appt->doctor_profile_id === (int) $user->doctorProfile->id) {
            return 'doctor';
        }

        return null;
    }

    /** @return 'patient'|'doctor' */
    public function authorize(Appointment $appt, ?User $user): string
    {
        $role = $this->roleFor($appt, $user);
        if (! $role || ! $appt->doctor_profile_id || $appt->status === Appointment::STATUS_MATCHING) {
            throw new DoctorMatchException('Appointment not found.', 404);
        }

        return $role;
    }

    protected function requireDoctor(Appointment $appt, ?User $user): void
    {
        if ($this->authorize($appt, $user) !== 'doctor') {
            throw new DoctorMatchException('Only the doctor can do this.', 403);
        }
    }

    /** Remote consults only: video (incl. telemed slot bookings) and call back. */
    public static function supports(Appointment $appt): bool
    {
        return $appt->isVideo() || $appt->isCallback();
    }

    public static function find(int $id): Appointment
    {
        $appt = Appointment::query()->with(['patient.user', 'doctor.user'])->find($id);
        if (! $appt) {
            throw new DoctorMatchException('Appointment not found.', 404);
        }

        return $appt;
    }

    // ------------------------------------------------------------------
    // Session
    // ------------------------------------------------------------------

    public function session(Appointment $appt): ConsultSession
    {
        $s = ConsultSession::query()->where('appointment_id', $appt->id)->first();
        if ($s) {
            return $s;
        }
        try {
            return ConsultSession::query()->create(['appointment_id' => $appt->id, 'channels' => []]);
        } catch (QueryException) {
            // Two requests raced on the unique key.
            return ConsultSession::query()->where('appointment_id', $appt->id)->firstOrFail();
        }
    }

    public function existingSession(Appointment $appt): ?ConsultSession
    {
        return ConsultSession::query()->where('appointment_id', $appt->id)->first();
    }

    public function isOpen(Appointment $appt, ?ConsultSession $s = null): bool
    {
        $s ??= $this->existingSession($appt);

        return in_array($appt->status, self::ACTIVE, true) && ! ($s && $s->ended_at);
    }

    /** Opening the hub page / screen. */
    public function open(Appointment $appt, User $user): ConsultSession
    {
        $this->authorize($appt, $user);
        if (! self::supports($appt)) {
            throw new DoctorMatchException('This appointment is not a video or call-back consult.', 422);
        }
        $s = $this->session($appt);
        if (! $s->opened_at && $this->isOpen($appt, $s)) {
            $s->forceFill(['opened_at' => now()])->save();
        }

        return $s;
    }

    // ------------------------------------------------------------------
    // URLs
    // ------------------------------------------------------------------

    public static function patientHubUrl(Appointment $appt): ?string
    {
        return Route::has('account.consult-hub') ? route('account.consult-hub', $appt) : null;
    }

    public static function doctorHubUrl(Appointment $appt): ?string
    {
        return class_exists(\App\Filament\Pages\ConsultHub::class)
            ? \App\Filament\Pages\ConsultHub::getUrl(['appointment' => $appt->id], panel: 'admin')
            : null;
    }

    // ------------------------------------------------------------------
    // Payload (web page, app screen, polling)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function payload(Appointment $appt, User $user): array
    {
        $role = $this->authorize($appt, $user);
        $appt->loadMissing(['patient.user', 'doctor.user']);
        $this->maybeAutoPick($appt);
        $s = $this->existingSession($appt) ?? $this->session($appt);
        $now = now();
        $open = $this->isOpen($appt, $s);
        $doctor = $appt->doctor;
        $doctorName = $doctor?->user ? 'Dr. '.$doctor->user->name : 'Your doctor';
        $patientName = (string) ($appt->patient?->user?->name ?? 'Patient');
        $ringing = $open && $this->calls->isRinging($appt);
        $ringChannel = $s->ring_channel ?: 'video';

        $doctorFresh = $s->doctor_seen_at && $s->doctor_seen_at->gte($now->copy()->subSeconds(self::PRESENCE_FRESH_SECONDS));
        $patientFresh = $s->patient_seen_at && $s->patient_seen_at->gte($now->copy()->subSeconds(self::PRESENCE_FRESH_SECONDS));
        $liveCall = $doctorFresh && $patientFresh;

        $inAppNow = $open && $appt->isVideo() && $appt->canJoinVideoNow();
        // A call-back consult becomes in-app when the doctor starts video / voice.
        $canStartInApp = $open && ($inAppNow || ($role === 'doctor' && $appt->isCallback()));
        $inAppReason = $open ? ($appt->isCallback()
            ? ($role === 'doctor' ? null : 'Your doctor can start a video or voice call from their side.')
            : ($appt->canJoinVideoNow() ? null : 'The call room opens 10 minutes before the appointment and closes 30 minutes after it ends.'))
            : 'This consult has ended.';

        $conversationId = null;
        try {
            $conversationId = app(ChatService::class)->forAppointment($appt, $user)?->id;
        } catch (Throwable) {
        }

        $digits = $this->calls->callbackDigits($appt);
        $callbackRequested = $appt->isCallback() || (bool) $appt->callback_channel;
        $callback = $this->calls->callbackPayload($appt, $role === 'doctor');
        if (! $callback && $callbackRequested) {
            // Video consult where the patient later asked for a phone call.
            $callback = [
                'phone_display' => ConsultCallService::displayPhone($digits),
                'channel' => $appt->callback_channel ?: 'either',
                'channel_label' => ConsultCallService::channelLabel($appt->callback_channel),
                'called_at' => $appt->called_at?->toIso8601String(),
                'call_attempts' => (int) $appt->call_attempts,
            ];
        }

        $connectedSeconds = $this->liveSeconds($s, $now);

        $matchReq = $this->matchRequest($appt);
        $choice = $matchReq ? $this->channelChoice($appt, $role, $matchReq) : null;
        $statusText = $this->choiceStatusText($role, $choice, $s, $open, $liveCall, $doctorName, $patientName)
            ?? $this->statusText($role, $appt, $s, $open, $ringing, $ringChannel, $liveCall, $doctorName, $patientName, $callback);

        $out = [
            'appointment_id' => $appt->id,
            'booking_ref' => $appt->booking_ref,
            'role' => $role,
            'mode' => $appt->isCallback() ? Appointment::MODE_CALLBACK : Appointment::MODE_VIDEO,
            'status' => $appt->status,
            'is_open' => $open,
            'status_text' => $statusText,
            'server_time' => $now->toIso8601String(),
            'doctor' => [
                'name' => $doctorName,
                'specialization' => $doctor?->specialization,
                'qualification' => $doctor?->qualification,
                'rating' => round((float) ($doctor?->rating_avg ?? 0), 1),
                'rating_count' => (int) ($doctor?->rating_count ?? 0),
                'photo_url' => \App\Support\Widgets\WidgetDataService::mediaUrl($doctor?->user?->avatar),
            ],
            'consult' => [
                'state' => $s->ended_at ? 'ended' : ($s->connected_at ? 'connected' : 'waiting'),
                'opened_at' => $s->opened_at?->toIso8601String(),
                'connected_at' => $s->connected_at?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'first_channel' => $s->first_channel,
                'current_channel' => $s->current_channel,
                'connected_seconds' => $connectedSeconds,
                'live_call' => $liveCall,
                'outcome' => $s->outcome,
                'outcome_label' => $s->outcome ? (ConsultSession::OUTCOMES[$s->outcome] ?? $s->outcome) : null,
                'ended_by' => $s->ended_by_role,
                'channels' => $this->presentSegments($s),
            ],
            'presence' => [
                'doctor' => $doctorFresh ? $s->doctor_channel : null,
                'patient' => $patientFresh ? $s->patient_channel : null,
            ],
            'ring' => $role === 'patient' && $ringing ? $this->calls->ringPayload($appt) : null,
            'ringing' => $ringing ? $ringChannel : null,
            'default_channel' => $appt->isCallback() ? 'callback' : 'video',
            'channels' => [
                'video' => ['available' => $canStartInApp, 'reason' => $inAppReason],
                'voice' => ['available' => $canStartInApp, 'reason' => $inAppReason],
                'chat' => ['available' => $conversationId !== null, 'conversation_id' => $conversationId],
                'phone' => ['available' => $open && ($role === 'patient' || $digits !== null)],
                'whatsapp' => ['available' => $open && ($role === 'patient' || $digits !== null)],
            ],
            'callback_requested' => $callbackRequested,
            'callback' => $callback,
            // Patient request scenario: "Dr X accepted — choose video / voice / WhatsApp" (null when not used).
            'channel_choice' => $choice,
            'urls' => [
                'hub' => $role === 'doctor' ? self::doctorHubUrl($appt) : self::patientHubUrl($appt),
                'request' => $role === 'patient' && Route::has('account.doctor-now') && DoctorMatchRequest::query()->where('appointment_id', $appt->id)->exists()
                    ? route('account.doctor-now') : null,
            ],
        ];

        if ($role === 'doctor') {
            $out['patient'] = $this->patientSummary($appt);
            $out['triage'] = $this->calls->triageSummary($this->triageFor($appt));
            $out['fallback'] = [
                'phone' => $digits ? '+'.$digits : null,
                'phone_display' => ConsultCallService::displayPhone($digits),
                'tel_url' => ConsultCallService::telUrl($digits),
                'whatsapp_url' => ConsultCallService::whatsappUrl($digits),
                'whatsapp_note' => 'WhatsApp has no link that starts a call directly: this opens the chat — tap the phone icon there to call.',
            ];
            $out['outcomes'] = collect(ConsultSession::OUTCOMES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all();
            $out['can_end'] = $open;
        } else {
            $out['emergency'] = $this->emergencyContact();
        }

        return $out;
    }

    protected function statusText(string $role, Appointment $appt, ConsultSession $s, bool $open, bool $ringing, string $ringChannel, bool $liveCall, string $doctorName, string $patientName, ?array $callback): string
    {
        if (! $open) {
            return $s->outcome ? 'Consult ended · '.(ConsultSession::OUTCOMES[$s->outcome] ?? $s->outcome) : 'This consult has ended';
        }
        $channelWord = fn (?string $c) => $c === 'voice' ? 'voice call' : ($c === 'video' ? 'video call' : ConsultSession::channelLabel($c));
        if ($liveCall) {
            return ($role === 'patient' ? 'Connected with '.$doctorName : 'Connected with '.$patientName).' · '.$channelWord($s->current_channel);
        }
        if ($role === 'patient') {
            if ($ringing) {
                return $doctorName.' is calling you ('.($ringChannel === 'voice' ? 'voice' : 'video').')…';
            }
            if (in_array($s->current_channel, self::FALLBACK_CHANNELS, true)) {
                return $doctorName.' · '.$channelWord($s->current_channel).' in progress';
            }
            if ($appt->isCallback() && $callback && ! $s->connected_at) {
                return $appt->called_at
                    ? $doctorName.' is calling you on '.($callback['phone_display'] ?? 'your phone')
                    : $doctorName.' accepted · will call you'.(! empty($callback['phone_display']) ? ' on '.$callback['phone_display'] : '');
            }

            return $s->connected_at ? $doctorName.' · consult in progress' : $doctorName.' accepted · connecting…';
        }
        $patientFirst = trim(strtok($patientName, ' ') ?: $patientName);
        if ($s->patient_seen_at && $s->patient_seen_at->gte(now()->subSeconds(self::PRESENCE_FRESH_SECONDS))) {
            return $patientFirst.' is waiting in the '.$channelWord($s->patient_channel);
        }
        if ($ringing) {
            return 'Ringing '.$patientFirst.' ('.($ringChannel === 'voice' ? 'voice' : 'video').')…';
        }
        if (in_array($s->current_channel, self::FALLBACK_CHANNELS, true)) {
            return $channelWord($s->current_channel).' with '.$patientFirst.' in progress';
        }

        return $s->connected_at ? 'Consult in progress with '.$patientFirst : 'Start a call with '.$patientFirst;
    }

    /** @return array<string, mixed> */
    protected function patientSummary(Appointment $appt): array
    {
        $p = $appt->patient;
        $brief = $this->calls->patientBrief($p);
        $meds = [];
        try {
            if ($p) {
                $meds = $p->medications()->latest('id')->limit(10)->get(['name', 'dose'])
                    ->map(fn ($m) => trim($m->name.' '.($m->dose ?? '')))->filter()->values()->all();
            }
        } catch (Throwable) {
            // Medications table not present on older installs.
        }

        return $brief + [
            'blood_group' => $p?->blood_group,
            'allergies' => $p?->allergies,
            'chronic_conditions' => $p?->chronic_conditions,
            'medications' => $meds,
            'reason' => $appt->reason,
            'emergency_contact' => $p?->emergency_contact_name ? trim($p->emergency_contact_name.' '.($p->emergency_contact_phone ?? '')) : null,
        ];
    }

    protected function triageFor(Appointment $appt): ?TriageSubmission
    {
        if ($appt->triage_submission_id) {
            return TriageSubmission::query()->find($appt->triage_submission_id);
        }
        $tid = DoctorMatchRequest::query()->where('appointment_id', $appt->id)->value('triage_submission_id');

        return $tid ? TriageSubmission::query()->find($tid) : null;
    }

    /** @return array{phone: ?string, tel_url: ?string}|null */
    protected function emergencyContact(): ?array
    {
        try {
            $ec = EmergencyContact::primary();
        } catch (Throwable) {
            $ec = null;
        }

        return $ec ? ['phone' => $ec->phone, 'tel_url' => $ec->tel_url] : null;
    }

    // ------------------------------------------------------------------
    // Channels
    // ------------------------------------------------------------------

    /**
     * Doctor: Start video / Start voice. Rings the patient (push + in-app
     * incoming call with the channel); a call-back consult becomes a video
     * consult first (the patient's number is still there as fallback).
     */
    public function startCall(Appointment $appt, User $doctorUser, string $channel, bool $override = false): Appointment
    {
        $this->requireDoctor($appt, $doctorUser);
        if (! in_array($channel, self::CALL_CHANNELS, true)) {
            throw new DoctorMatchException('Unknown channel.', 422);
        }
        $s = $this->session($appt);
        if (! $this->isOpen($appt, $s)) {
            throw new DoctorMatchException('This consult has ended.', 409);
        }
        $req = $this->matchRequest($appt);
        if ($req && $req->channel_pending) {
            if (! $override) {
                // Older doctor app builds auto-start video on accept: wait for the patient's choice instead.
                throw new DoctorMatchException('Waiting for the patient to choose video, voice or WhatsApp. Tap "Start anyway" to call them now.', 409);
            }
            $this->resolvePending($req, $channel, 'doctor');
        }
        if ($appt->isCallback()) {
            $appt->mode = Appointment::MODE_VIDEO;
            $appt->visit_type = 'telemed';
            $appt->save(); // saving hook generates the video room
            DoctorMatchRequest::query()->where('appointment_id', $appt->id)->update(['mode' => Appointment::MODE_VIDEO, 'updated_at' => now()]);
            $appt = $appt->fresh(['patient.user', 'doctor.user']);
        }
        if (! $appt->canJoinVideoNow()) {
            throw new DoctorMatchException('The call room opens 10 minutes before the appointment and closes 30 minutes after it ends.', 423);
        }
        if (! $s->opened_at) {
            $s->opened_at = now();
        }
        $s->forceFill(['current_channel' => $channel])->save();
        $this->calls->ring($appt, true, $channel);

        return $appt->fresh(['patient.user', 'doctor.user']);
    }

    /**
     * Heartbeat from a participant's own client while they are inside the
     * video / voice call ($in = true), or when they leave ($in = false).
     * Both inside → a connected call segment (and ConsultStarted once).
     */
    public function presence(Appointment $appt, User $user, string $channel, bool $in): ConsultSession
    {
        $role = $this->authorize($appt, $user);
        if (! in_array($channel, self::CALL_CHANNELS, true)) {
            throw new DoctorMatchException('Unknown channel.', 422);
        }
        $this->session($appt);
        if ($in && $role === 'patient' && ($req = $this->matchRequest($appt)) && $req->channel_pending) {
            // The patient went straight into the call (e.g. an older app build's Video button): that is their choice.
            $this->resolvePending($req, $channel, 'patient');
            $this->alertDoctorOfChoice($appt->fresh(['patient.user', 'doctor.user']), $channel);
        }
        $connectedChannel = null;

        $s = DB::transaction(function () use ($appt, $role, $channel, $in, &$connectedChannel) {
            /** @var ConsultSession $s */
            $s = ConsultSession::query()->where('appointment_id', $appt->id)->lockForUpdate()->firstOrFail();
            if ($s->ended_at) {
                return $s;
            }
            $now = now();
            $s->forceFill([$role.'_seen_at' => $in ? $now : null, $role.'_channel' => $in ? $channel : null]);

            $fresh = fn (?Carbon $t) => $t && $t->gte($now->copy()->subSeconds(self::PRESENCE_FRESH_SECONDS));
            $segments = $s->channels ?? [];
            $openIdx = $this->openCallSegment($segments);

            if ($fresh($s->doctor_seen_at) && $fresh($s->patient_seen_at)) {
                $ch = $s->doctor_channel === 'video' && $s->patient_channel === 'video' ? 'video' : 'voice';
                $continues = $openIdx !== null && $segments[$openIdx]['channel'] === $ch
                    && Carbon::parse($segments[$openIdx]['last_at'])->gte($now->copy()->subSeconds(self::SEGMENT_GAP_SECONDS));
                if ($continues) {
                    $segments[$openIdx]['last_at'] = $now->toIso8601String();
                } else {
                    if ($openIdx !== null) {
                        $segments[$openIdx] = $this->closeSegment($segments[$openIdx], null);
                    }
                    $segments = $this->closeFallbackSegments($segments, $now);
                    $segments[] = ['channel' => $ch, 'started_at' => $now->toIso8601String(), 'last_at' => $now->toIso8601String(), 'ended_at' => null];
                }
                $s->current_channel = $ch;
                $connectedChannel = $ch;
            } elseif ($openIdx !== null) {
                $segments[$openIdx] = $this->closeSegment($segments[$openIdx], null);
            }
            if (! $in && $s->current_channel === $channel && $connectedChannel === null) {
                // Left the call: keep the hub, nothing live right now.
                $s->current_channel = null;
            } elseif ($in && ! $s->current_channel) {
                $s->current_channel = $channel;
            }

            $s->channels = array_values($segments);
            $s->connected_seconds = $this->sumSeconds($segments, $now);
            $s->save();

            return $s;
        });

        if ($connectedChannel) {
            $this->markConnected($s, $appt, $connectedChannel);
        }
        // Call records / consultation evidence (join + leave per participant).
        app(\App\Services\PracticeCalls\CallLogService::class)->consultPresence($appt, $user, $channel, $in);

        return $s->fresh();
    }

    /** Doctor: tapped Call / WhatsApp in the fallback block (SLA stamp, patient told to pick up). */
    public function calling(Appointment $appt, User $doctorUser, string $via): Appointment
    {
        $this->requireDoctor($appt, $doctorUser);
        if (! in_array($via, self::FALLBACK_CHANNELS, true)) {
            throw new DoctorMatchException('Unknown channel.', 422);
        }
        if (! $this->isOpen($appt)) {
            throw new DoctorMatchException('This consult has ended.', 409);
        }
        if (($req = $this->matchRequest($appt)) && $req->channel_pending) {
            $this->resolvePending($req, $via, 'doctor');
        }
        if ($appt->isCallback()) {
            return $this->calls->markCalling($appt, $doctorUser->doctorProfile, $via);
        }
        $appt->forceFill(['called_at' => $appt->called_at ?? now(), 'call_attempts' => (int) $appt->call_attempts + 1, 'last_call_attempt_at' => now()])->saveQuietly();
        $this->notifyPatient($appt, ($appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : 'Your doctor').' is calling you', 'Answer your phone — the doctor is calling you '.($via === 'whatsapp' ? 'on WhatsApp' : 'by phone').'.');

        return $appt->fresh();
    }

    /** Doctor: "Patient answered" on a phone / WhatsApp call — the consult is connected. */
    public function phoneConnected(Appointment $appt, User $doctorUser, string $via): ConsultSession
    {
        $this->requireDoctor($appt, $doctorUser);
        if (! in_array($via, self::FALLBACK_CHANNELS, true)) {
            throw new DoctorMatchException('Unknown channel.', 422);
        }
        $s = $this->session($appt);
        if (! $this->isOpen($appt, $s)) {
            throw new DoctorMatchException('This consult has ended.', 409);
        }
        if (($req = $this->matchRequest($appt)) && $req->channel_pending) {
            $this->resolvePending($req, $via, 'doctor');
        }

        $s = DB::transaction(function () use ($appt, $via) {
            $s = ConsultSession::query()->where('appointment_id', $appt->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $segments = $s->channels ?? [];
            $openIdx = $this->openCallSegment($segments);
            if ($openIdx !== null) {
                $segments[$openIdx] = $this->closeSegment($segments[$openIdx], null);
            }
            $already = false;
            foreach ($segments as $seg) {
                if ($seg['channel'] === $via && empty($seg['ended_at'])) {
                    $already = true;
                }
            }
            if (! $already) {
                $segments = $this->closeFallbackSegments($segments, $now);
                $segments[] = ['channel' => $via, 'started_at' => $now->toIso8601String(), 'last_at' => null, 'ended_at' => null];
            }
            $s->forceFill(['channels' => array_values($segments), 'current_channel' => $via, 'connected_seconds' => $this->sumSeconds($segments, $now)])->save();

            return $s;
        });
        if (! $appt->called_at) {
            $appt->forceFill(['called_at' => now()])->saveQuietly();
        }
        $this->markConnected($s, $appt, $via);

        return $s->fresh();
    }

    /**
     * Patient fallback: "Ask the doctor to phone me" / "…WhatsApp me".
     * Stores the preference (and a number if given) and alerts the doctor.
     */
    public function requestCallback(Appointment $appt, User $patientUser, string $via, ?string $phone = null): Appointment
    {
        if ($this->authorize($appt, $patientUser) !== 'patient') {
            throw new DoctorMatchException('Appointment not found.', 404);
        }
        if (! in_array($via, self::FALLBACK_CHANNELS, true)) {
            throw new DoctorMatchException('Unknown channel.', 422);
        }
        if (! $this->isOpen($appt)) {
            throw new DoctorMatchException('This consult has ended.', 409);
        }
        $attrs = ['callback_channel' => $via];
        if ($phone !== null && trim($phone) !== '') {
            $digits = ConsultCallService::normalisePhone($phone);
            if (! $digits) {
                throw new DoctorMatchException('Enter a valid phone number.', 422);
            }
            $attrs['callback_phone'] = $digits;
        }
        $appt->forceFill($attrs)->saveQuietly();
        $appt = $appt->fresh(['patient.user', 'doctor.user']);
        $digits = $this->calls->callbackDigits($appt);
        if (! $digits) {
            throw new DoctorMatchException('Add the number the doctor should call.', 422);
        }

        $doctorUser = $appt->doctor?->user;
        if ($doctorUser) {
            $title = ($appt->patient?->user?->name ?? 'Your patient').' asks you to '.($via === 'whatsapp' ? 'WhatsApp-call' : 'phone').' them';
            $body = 'Connection problems in the app. Call '.ConsultCallService::displayPhone($digits).' — open the consult hub for the Call / WhatsApp buttons.';
            try {
                app(InAppNotifier::class)->send($doctorUser, 'appointment', $title, $body, ['route' => 'consult_hub', 'params' => ['appointment_id' => $appt->id], 'url' => self::doctorHubUrl($appt)]);
                StaffNotifier::alert($doctorUser, $title, $body, icon: 'heroicon-o-phone-arrow-up-right', color: 'warning');
            } catch (Throwable $e) {
                Log::warning('[ConsultHub] callback request alert failed: '.$e->getMessage());
            }
        }

        return $appt;
    }

    /** ChatService hook: the doctor's first message in an open hub consult counts as connected (chat). */
    public function onChatMessage(Conversation $conversation, User $sender, Message $message): void
    {
        if (! $sender->isDoctor() || ! $conversation->appointment_id) {
            return;
        }
        $appt = Appointment::query()->find($conversation->appointment_id);
        if (! $appt || (int) $appt->doctor_profile_id !== (int) $sender->doctorProfile?->id) {
            return;
        }
        $s = $this->existingSession($appt);
        // Only once the consult is live in the hub (a message days before a booking is not a consult).
        if (! $s || ! $this->isOpen($appt, $s) || ! ($s->opened_at || $s->ring_channel)) {
            return;
        }

        $segments = $s->channels ?? [];
        $hasChat = collect($segments)->contains(fn ($seg) => ($seg['channel'] ?? null) === 'chat');
        if (! $hasChat) {
            $now = now();
            $segments[] = ['channel' => 'chat', 'started_at' => $now->toIso8601String(), 'last_at' => null, 'ended_at' => null];
            $s->forceFill(['channels' => $segments])->save();
        }
        $this->markConnected($s, $appt, 'chat');
    }

    /**
     * Doctor ends the consult with an outcome (+ optional notes). Closes the
     * appointment, notifies the patient and fires ConsultEnded once. A repeat
     * call (double tap / retry) returns the already-ended session.
     */
    public function end(Appointment $appt, User $user, string $outcome, ?string $notes = null): ConsultSession
    {
        $this->requireDoctor($appt, $user);
        if (! isset(ConsultSession::OUTCOMES[$outcome])) {
            throw new DoctorMatchException('Unknown outcome.', 422);
        }
        $s = $this->session($appt);
        if ($s->ended_at) {
            return $s;
        }
        if (! in_array($appt->status, self::ACTIVE, true)) {
            throw new DoctorMatchException('This consult is already closed.', 409);
        }

        $ended = $this->closeSession($s, $outcome, $notes, $user->id, 'doctor');
        if (! $ended) {
            return $s->fresh();
        }

        // Appointment status (observer releases the on-demand doctor / settles a matched request).
        $appt = $appt->fresh(['patient.user', 'doctor.user']);
        $appt->forceFill(match ($outcome) {
            'completed', 'escalated', 'no_answer' => $appt->isCallback() ? ['call_outcome' => $outcome, 'call_outcome_at' => now(), 'called_at' => $appt->called_at ?? now()] : [],
            default => [],
        });
        $appt->status = match ($outcome) {
            'completed', 'escalated' => Appointment::STATUS_COMPLETED,
            'patient_no_show', 'no_answer' => Appointment::STATUS_NO_SHOW,
            default => Appointment::STATUS_CANCELLED,
        };
        if ($appt->status === Appointment::STATUS_CANCELLED) {
            $appt->cancel_reason = $outcome === 'failed' ? 'Consult could not take place (technical problem)' : 'The doctor could not take this consult';
        }
        $appt->save();

        $this->notifyEnded($appt, $outcome);
        $this->fireEnded($appt, $outcome);
        app(\App\Services\PracticeCalls\CallLogService::class)->consultEnded($appt, $user, $outcome);

        return $s->fresh();
    }

    /**
     * Legacy call-back outcome buttons (CallbackConsult page / older app
     * builds): a "done" / "escalated" outcome ends the hub session too.
     */
    public function closeFromCallbackOutcome(Appointment $appt, User $doctorUser, string $outcome): void
    {
        if (! in_array($outcome, ['completed', 'escalated'], true)) {
            return;
        }
        $s = $this->session($appt);
        if ($s->ended_at) {
            return;
        }
        if (! $s->connected_at) {
            // The doctor reports the call happened: record it as a phone consult.
            $via = $appt->callback_channel === 'whatsapp' ? 'whatsapp' : 'phone';
            $segments = $s->channels ?? [];
            $segments[] = ['channel' => $via, 'started_at' => ($appt->called_at ?? now())->toIso8601String(), 'last_at' => null, 'ended_at' => null];
            $s->forceFill(['channels' => $segments, 'current_channel' => $via])->save();
            $this->markConnected($s, $appt, $via);
        }
        if ($this->closeSession($s->fresh(), $outcome, null, $doctorUser->id, 'doctor')) {
            $this->fireEnded($appt->fresh(), $outcome);
        }
    }

    /**
     * Appointment status changed elsewhere (doctor marked it completed in
     * the panel, admin set no-show / cancelled): close the open session.
     * Completed / no-show fire ConsultEnded; a cancellation is settled by the
     * existing cancellation flow, so the session is closed without the event.
     */
    public function onAppointmentStatusChanged(Appointment $appt): void
    {
        if (in_array($appt->status, self::ACTIVE, true)) {
            return;
        }
        $s = $this->existingSession($appt);
        if (! $s || $s->ended_at) {
            return;
        }
        $outcome = match ($appt->status) {
            Appointment::STATUS_COMPLETED => 'completed',
            Appointment::STATUS_NO_SHOW => 'patient_no_show',
            default => 'cancelled',
        };
        if ($this->closeSession($s, $outcome, null, null, 'system') && $outcome !== 'cancelled') {
            $this->fireEnded($appt, $outcome);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** First connection on any channel → ConsultStarted, exactly once (conditional UPDATE). */
    protected function markConnected(ConsultSession $s, Appointment $appt, string $channel): void
    {
        $now = now();
        $won = ConsultSession::query()->whereKey($s->id)->whereNull('connected_at')->whereNull('ended_at')
            ->update(['connected_at' => $now, 'first_channel' => $channel, 'updated_at' => $now]);
        if ($won !== 1) {
            return;
        }
        // The patient is in: stop any ring still showing.
        try {
            if (in_array($channel, self::CALL_CHANNELS, true) && $appt->patient?->user) {
                $this->calls->answer($appt, $appt->patient->user);
            }
        } catch (Throwable) {
        }
        try {
            event(new ConsultStarted($appt->fresh(), $channel));
        } catch (Throwable $e) {
            // A listener must never break the call itself.
            Log::error('[ConsultHub] ConsultStarted listener failed: '.$e->getMessage(), ['appointment_id' => $appt->id]);
        }
    }

    /** Close segments and stamp the end — true only for the caller that actually ended it. */
    protected function closeSession(ConsultSession $s, string $outcome, ?string $notes, ?int $userId, string $role): bool
    {
        $now = now();
        $segments = array_map(function ($seg) use ($now) {
            if (! empty($seg['ended_at'])) {
                return $seg;
            }

            return in_array($seg['channel'], self::CALL_CHANNELS, true) ? $this->closeSegment($seg, null) : $this->closeSegment($seg, $now);
        }, $s->channels ?? []);

        $won = ConsultSession::query()->whereKey($s->id)->whereNull('ended_at')->update([
            'ended_at' => $now,
            'ended_by_user_id' => $userId,
            'ended_by_role' => $role,
            'outcome' => $outcome,
            'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 2000) : null,
            'current_channel' => null,
            'doctor_seen_at' => null,
            'patient_seen_at' => null,
            'channels' => json_encode(array_values($segments)),
            'connected_seconds' => $this->sumSeconds($segments, $now),
            'updated_at' => $now,
        ]);

        return $won === 1;
    }

    protected function fireEnded(Appointment $appt, string $outcome): void
    {
        try {
            event(new ConsultEnded($appt->fresh() ?? $appt, self::EVENT_OUTCOME[$outcome] ?? $outcome));
        } catch (Throwable $e) {
            Log::error('[ConsultHub] ConsultEnded listener failed: '.$e->getMessage(), ['appointment_id' => $appt->id]);
        }
    }

    protected function openCallSegment(array $segments): ?int
    {
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (in_array($segments[$i]['channel'] ?? null, self::CALL_CHANNELS, true) && empty($segments[$i]['ended_at'])) {
                return $i;
            }
        }

        return null;
    }

    protected function closeFallbackSegments(array $segments, Carbon $now): array
    {
        foreach ($segments as $i => $seg) {
            if (in_array($seg['channel'] ?? null, self::FALLBACK_CHANNELS, true) && empty($seg['ended_at'])) {
                $segments[$i] = $this->closeSegment($seg, $now);
            }
        }

        return $segments;
    }

    /** Call segments end at their last heartbeat; phone / chat at $at. */
    protected function closeSegment(array $seg, ?Carbon $at): array
    {
        $end = $at ?? (! empty($seg['last_at']) ? Carbon::parse($seg['last_at']) : Carbon::parse($seg['started_at']));
        $seg['ended_at'] = $end->toIso8601String();
        $seg['seconds'] = max(0, (int) Carbon::parse($seg['started_at'])->diffInSeconds($end, false));
        if (($seg['channel'] ?? null) === 'chat') {
            $seg['seconds'] = 0;
        }

        return $seg;
    }

    protected function segmentSeconds(array $seg, Carbon $now): int
    {
        if (($seg['channel'] ?? null) === 'chat') {
            return 0;
        }
        if (isset($seg['seconds']) && ! empty($seg['ended_at'])) {
            return (int) $seg['seconds'];
        }
        $start = Carbon::parse($seg['started_at']);
        $end = in_array($seg['channel'], self::CALL_CHANNELS, true)
            ? Carbon::parse($seg['last_at'] ?? $seg['started_at'])
            : $now;

        return max(0, (int) $start->diffInSeconds($end, false));
    }

    protected function sumSeconds(array $segments, Carbon $now): int
    {
        return array_sum(array_map(fn ($seg) => $this->segmentSeconds($seg, $now), $segments));
    }

    protected function liveSeconds(ConsultSession $s, Carbon $now): int
    {
        return $s->ended_at ? (int) $s->connected_seconds : $this->sumSeconds($s->channels ?? [], $now);
    }

    /** @return list<array<string, mixed>> */
    public function presentSegments(ConsultSession $s): array
    {
        $now = now();

        return array_values(array_map(fn ($seg) => [
            'channel' => $seg['channel'],
            'label' => ConsultSession::channelLabel($seg['channel']),
            'started_at' => $seg['started_at'] ?? null,
            'ended_at' => $seg['ended_at'] ?? null,
            'seconds' => $this->segmentSeconds($seg, $now),
        ], $s->channels ?? []));
    }

    // ------------------------------------------------------------------
    // Channel choice after accept (patient request scenarios)
    // ------------------------------------------------------------------

    public function matchRequest(Appointment $appt): ?DoctorMatchRequest
    {
        return DoctorMatchRequest::query()->where('appointment_id', $appt->id)->latest('id')->first();
    }

    /** E.164 digits of the doctor's consult WhatsApp number (doctor profile), if any. */
    public static function doctorWhatsapp(Appointment $appt): ?string
    {
        $raw = $appt->doctor?->whatsapp_number;

        return $raw ? ConsultCallService::normalisePhone($raw) : null;
    }

    /** wa.me link to the doctor with a prefilled message naming the consult. */
    public static function doctorWhatsappUrl(Appointment $appt): ?string
    {
        $digits = self::doctorWhatsapp($appt);
        if (! $digits) {
            return null;
        }
        $patient = trim((string) ($appt->patient?->user?->name ?? ''));
        $doctor = $appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : 'Doctor';
        $text = 'Hi '.$doctor.', this is '.($patient !== '' ? $patient : 'your patient').' — Prompt Aid consult '.($appt->booking_ref ?: '#'.$appt->id).'. I would like a WhatsApp call, please.';

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
    }

    /**
     * Channels the patient may pick for this consult: the scenario's list,
     * minus what can't work here — video / voice need an in-app room,
     * WhatsApp needs the doctor's consult WhatsApp number (hidden otherwise),
     * a phone call-back needs a patient number (asked for when missing).
     *
     * @return array<int, array{value: string, label: string, hint: string}>
     */
    public function choiceOptions(Appointment $appt, DoctorMatchRequest $req): array
    {
        $chs = (array) (($req->scenario['channels'] ?? null) ?: ['video', 'voice', 'whatsapp']);
        $docWa = self::doctorWhatsapp($appt);
        $hints = [
            'video' => 'Face to face in the app — uses data',
            'voice' => 'Audio only in the app — uses data, not airtime',
            'whatsapp' => 'Message or call the doctor on WhatsApp',
            'phone' => 'The doctor phones your number',
            'chat' => 'Type and send photos in the app',
        ];
        $out = [];
        foreach ($chs as $c) {
            if (! isset(\App\Services\Triage\ScenarioEngine::CHANNELS[$c])) {
                continue;
            }
            if (in_array($c, self::CALL_CHANNELS, true) && ! $appt->isVideo()) {
                continue;
            }
            if ($c === 'whatsapp' && ! $docWa) {
                continue;
            }
            $out[] = ['value' => $c, 'label' => \App\Services\Triage\ScenarioEngine::CHANNELS[$c], 'hint' => $hints[$c] ?? ''];
        }
        if (! $out) {
            $out[] = ['value' => 'chat', 'label' => \App\Services\Triage\ScenarioEngine::CHANNELS['chat'], 'hint' => $hints['chat']];
        }

        return $out;
    }

    /**
     * Payload block for both sides (null when this consult does not use a
     * channel choice).
     *
     * @return array<string, mixed>|null
     */
    public function channelChoice(Appointment $appt, string $role, ?DoctorMatchRequest $req = null): ?array
    {
        $req ??= $this->matchRequest($appt);
        if (! $req || (! $req->channel_pending && ! \App\Services\Doctors\DoctorMatchService::choosesAfterAccept($req) && $req->channel_chosen_by !== 'auto')) {
            return null;
        }
        $appt->loadMissing(['patient.user', 'doctor.user']);
        $options = $this->choiceOptions($appt, $req);
        $values = array_column($options, 'value');
        $default = (string) ($req->scenario['default_channel'] ?? 'video');
        if (! in_array($default, $values, true)) {
            $default = $values[0] ?? 'video';
        }
        $doctorName = $appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : 'Your doctor';
        $chosen = $req->chosen_channel;
        $autoPick = (int) ($req->scenario['auto_pick_after_seconds'] ?? 0);
        $digits = $this->calls->callbackDigits($appt) ?? ConsultCallService::normalisePhone($appt->patient?->user?->phone);

        $out = [
            'pending' => (bool) $req->channel_pending,
            'title' => str_replace('your doctor', $doctorName, (string) (($req->scenario['choice_title'] ?? null) ?: 'How would you like to talk to your doctor?')),
            'options' => $options,
            'default' => $default,
            'chosen' => $chosen,
            'chosen_label' => $chosen ? (\App\Services\Triage\ScenarioEngine::CHANNELS[$chosen] ?? $chosen) : null,
            'chosen_by' => $req->channel_chosen_by,
            'chosen_at' => $req->channel_chosen_at?->toIso8601String(),
            'auto_pick_at' => $req->channel_pending && $autoPick > 0 && $req->matched_at ? $req->matched_at->copy()->addSeconds($autoPick)->toIso8601String() : null,
        ];
        if ($role === 'patient') {
            $out['phone_display'] = ConsultCallService::displayPhone($digits);
            // The doctor's WhatsApp link only once WhatsApp is (or can be) the patient's choice.
            $out['whatsapp_url'] = in_array('whatsapp', $values, true) || $chosen === 'whatsapp' ? self::doctorWhatsappUrl($appt) : null;
            $out['choose_url'] = Route::has('consult-hub.choose') ? route('consult-hub.choose', $appt->id) : null;
        } else {
            $out['patient_phone'] = $digits ? '+'.$digits : null;
            $out['patient_phone_display'] = ConsultCallService::displayPhone($digits);
            $out['tel_url'] = ConsultCallService::telUrl($digits);
        }

        return $out;
    }

    protected function choiceStatusText(string $role, ?array $choice, ConsultSession $s, bool $open, bool $liveCall, string $doctorName, string $patientName): ?string
    {
        if (! $choice || ! $open || $liveCall || $s->connected_at) {
            return null;
        }
        $first = trim(strtok($patientName, ' ') ?: $patientName);
        $labels = \App\Services\Triage\ScenarioEngine::humanList(array_map(fn ($o) => mb_strtolower($o['label']), $choice['options']), 'or');
        if ($choice['pending']) {
            return $role === 'patient'
                ? $doctorName.' accepted · choose how you would like to talk'
                : 'Waiting for '.$first.' to choose: '.$labels;
        }

        return match ($choice['chosen']) {
            'whatsapp' => $role === 'patient'
                ? 'Contact '.$doctorName.' on WhatsApp — tap “Open WhatsApp”'
                : $first.' is contacting you on WhatsApp'.(! empty($choice['patient_phone_display']) ? ' ('.$choice['patient_phone_display'].')' : '').' — tap “Patient connected (WhatsApp)” once you are talking',
            'phone' => $role === 'patient'
                ? $doctorName.' will phone you'.(! empty($choice['phone_display']) ? ' on '.$choice['phone_display'] : '')
                : $first.' asked you to phone them'.(! empty($choice['patient_phone_display']) ? ' on '.$choice['patient_phone_display'] : ''),
            'chat' => $role === 'patient' ? 'Chat with '.$doctorName.' below' : $first.' chose chat — send the first message',
            'video', 'voice' => $role === 'patient'
                ? 'Waiting for '.$doctorName.' to join the '.($choice['chosen'] === 'voice' ? 'voice' : 'video').' call…'
                : $first.' chose a '.($choice['chosen'] === 'voice' ? 'voice' : 'video').' call — join now',
            default => null,
        };
    }

    /**
     * Patient: picks the channel after the doctor accepted. Idempotent for
     * the same choice; a different choice once one is made → 409 (switch
     * from the hub's own buttons instead).
     */
    public function chooseChannel(Appointment $appt, User $patientUser, string $channel, ?string $phone = null): Appointment
    {
        if ($this->authorize($appt, $patientUser) !== 'patient') {
            throw new DoctorMatchException('Only the patient chooses the channel.', 403);
        }
        if (! $this->isOpen($appt)) {
            throw new DoctorMatchException('This consult has ended.', 409);
        }
        $req = $this->matchRequest($appt);
        if (! $req) {
            throw new DoctorMatchException('This consult has no channel to choose.', 422);
        }
        $appt->loadMissing(['patient.user', 'doctor.user']);
        if (! in_array($channel, array_column($this->choiceOptions($appt, $req), 'value'), true)) {
            throw new DoctorMatchException($channel === 'whatsapp' ? 'WhatsApp is not available for this doctor. Please choose another option.' : 'That option is not available for this consult.', 422);
        }
        if (! $req->channel_pending) {
            if ($req->chosen_channel === $channel) {
                return $appt->fresh(['patient.user', 'doctor.user']);
            }
            throw new DoctorMatchException('You already chose '.mb_strtolower(\App\Services\Triage\ScenarioEngine::CHANNELS[$req->chosen_channel] ?? 'a channel').'. Use the buttons on the consult screen to switch.', 409);
        }
        if ($channel === 'phone') {
            $digits = ConsultCallService::normalisePhone($phone) ?? $this->calls->callbackDigits($appt) ?? ConsultCallService::normalisePhone($patientUser->phone);
            if (! $digits) {
                throw new DoctorMatchException('Enter the number the doctor should call.', 422);
            }
            $phone = $digits;
        }
        if (! $this->resolvePending($req, $channel, 'patient')) {
            // Lost a race (doctor started / auto-pick): report what was chosen.
            $req->refresh();
            if ($req->chosen_channel !== $channel) {
                throw new DoctorMatchException('The consult already started by '.mb_strtolower(\App\Services\Triage\ScenarioEngine::CHANNELS[$req->chosen_channel] ?? 'another channel').'.', 409);
            }

            return $appt->fresh(['patient.user', 'doctor.user']);
        }
        $this->applyChannel($appt->fresh(['patient.user', 'doctor.user']), $req->fresh(), $channel, 'patient', $phone);

        return $appt->fresh(['patient.user', 'doctor.user']);
    }

    /** Close the pending choice exactly once (conditional UPDATE). */
    public function resolvePending(DoctorMatchRequest $req, string $channel, string $by): bool
    {
        $n = DoctorMatchRequest::query()->whereKey($req->id)->where('channel_pending', true)->update([
            'channel_pending' => false,
            'chosen_channel' => $channel,
            'channel_chosen_at' => now(),
            'channel_chosen_by' => $by,
            'updated_at' => now(),
        ]);

        return $n === 1;
    }

    /**
     * Effects of a chosen channel. Patient chose video / voice → they join
     * the room themselves and the doctor is told to join; chosen by the
     * scenario / auto-pick → the patient's screen rings (auto-joins).
     * WhatsApp → the patient opens wa.me/<doctor>; the doctor is told the
     * patient is contacting them and marks "Patient connected (WhatsApp)".
     * Phone → the doctor is asked to phone the patient. Chat → doctor alerted.
     */
    public function applyChannel(Appointment $appt, DoctorMatchRequest $req, string $channel, string $by, ?string $phone = null): void
    {
        $appt->loadMissing(['patient.user', 'doctor.user']);
        $s = $this->session($appt);
        if (in_array($channel, self::CALL_CHANNELS, true)) {
            if (! $s->opened_at) {
                $s->forceFill(['opened_at' => now()])->save();
            }
            if ($by !== 'patient') {
                $this->calls->ring($appt, true, $channel);
            }
        } elseif ($channel === 'phone') {
            $attrs = ['callback_channel' => 'phone'];
            if ($phone) {
                $attrs['callback_phone'] = ConsultCallService::normalisePhone($phone) ?? $phone;
            }
            $appt->forceFill($attrs)->saveQuietly();
        } elseif ($channel === 'whatsapp') {
            $appt->forceFill(['callback_channel' => 'whatsapp'])->saveQuietly();
        } elseif ($channel === 'chat' && ! $s->opened_at) {
            $s->forceFill(['opened_at' => now()])->save();
        }
        $this->alertDoctorOfChoice($appt->fresh(['patient.user', 'doctor.user']), $channel);
    }

    protected function alertDoctorOfChoice(Appointment $appt, string $channel): void
    {
        $doctorUser = $appt->doctor?->user;
        if (! $doctorUser) {
            return;
        }
        $name = $appt->patient?->user?->name ?? 'Your patient';
        $digits = $this->calls->callbackDigits($appt) ?? ConsultCallService::normalisePhone($appt->patient?->user?->phone);
        [$title, $body] = match ($channel) {
            'video' => [$name.' chose a video call', 'Join the video call now — the patient is waiting in the consult hub.'],
            'voice' => [$name.' chose an in-app voice call', 'Join the voice call now — the patient is waiting in the consult hub.'],
            'whatsapp' => [$name.' is contacting you on WhatsApp', 'Answer their WhatsApp message / call'.($digits ? ' ('.ConsultCallService::displayPhone($digits).')' : '').', then tap “Patient connected (WhatsApp)” in the consult hub.'],
            'phone' => [$name.' asks you to phone them', 'Call '.(ConsultCallService::displayPhone($digits) ?? 'the patient').' now — the Call button is in the consult hub.'],
            default => [$name.' chose chat', 'Send the first message in the consult hub.'],
        };
        $url = self::doctorHubUrl($appt);
        $join = in_array($channel, self::CALL_CHANNELS, true) && $url ? $url.'?join='.$channel : $url;
        try {
            app(InAppNotifier::class)->send($doctorUser, 'appointment', $title, $body, ['type' => 'consult_hub', 'route' => 'consult_hub', 'appointment_id' => $appt->id, 'channel' => $channel, 'params' => ['appointment_id' => $appt->id, 'channel' => $channel], 'url' => $join]);
            StaffNotifier::alert($doctorUser, $title, $body, icon: 'heroicon-o-video-camera', color: 'success', url: $join, actionLabel: 'Open consult');
        } catch (Throwable $e) {
            Log::warning('[ConsultHub] choice alert failed: '.$e->getMessage());
        }
    }

    /** Scenario "pick the default automatically after N s" — runs lazily on every hub / request poll. */
    public function maybeAutoPick(Appointment $appt): void
    {
        $req = $this->matchRequest($appt);
        if (! $req || ! $req->channel_pending || ! $req->matched_at) {
            return;
        }
        $secs = (int) ($req->scenario['auto_pick_after_seconds'] ?? 0);
        if ($secs <= 0 || $req->matched_at->copy()->addSeconds($secs)->isFuture() || ! $this->isOpen($appt)) {
            return;
        }
        $appt->loadMissing(['patient.user', 'doctor.user']);
        $values = array_column($this->choiceOptions($appt, $req), 'value');
        $default = (string) ($req->scenario['default_channel'] ?? 'video');
        $channel = in_array($default, $values, true) ? $default : ($values[0] ?? 'video');
        if ($this->resolvePending($req, $channel, 'auto')) {
            try {
                $this->applyChannel($appt, $req->fresh(), $channel, 'auto');
            } catch (Throwable $e) {
                Log::warning('[ConsultHub] auto-pick failed: '.$e->getMessage());
            }
        }
    }

    protected function notifyPatient(Appointment $appt, string $title, string $body, array $extra = []): void
    {
        $patientUser = $appt->patient?->user;
        if (! $patientUser) {
            return;
        }
        $data = $extra + ['type' => 'consult_hub', 'route' => 'consult_hub', 'appointment_id' => $appt->id, 'params' => ['appointment_id' => $appt->id], 'url' => self::patientHubUrl($appt)];
        try {
            app(InAppNotifier::class)->send($patientUser, 'appointment', $title, $body, $data);
        } catch (Throwable $e) {
            Log::warning('[ConsultHub] patient notify failed: '.$e->getMessage());
        }
    }

    protected function notifyEnded(Appointment $appt, string $outcome): void
    {
        $doctorName = $appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : 'Your doctor';
        $ec = $this->emergencyContact();
        [$title, $body, $extra] = match ($outcome) {
            'escalated' => ['Please call an ambulance now', $doctorName.' advises you to get emergency care now. Call '.($ec['phone'] ?? '10177').' or 112 from a mobile.', $ec ? ['tel_url' => $ec['tel_url']] : []],
            'patient_no_show', 'no_answer' => ['Consult closed', $doctorName.' could not reach you, so the consult was closed. You can request a doctor again at any time.', []],
            'failed', 'doctor_cancelled' => ['Consult could not take place', $doctorName.' could not complete your consult. Please request a doctor again.', []],
            default => ['Consult complete', 'Your consult with '.$doctorName.' is complete. Thank you for using Prompt Aid.', []],
        };
        $this->notifyPatient($appt, $title, $body, $extra);

        if ($outcome === 'escalated') {
            try {
                $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();
                if ($admins->isNotEmpty()) {
                    StaffNotifier::alert($admins, 'Consult escalated to ambulance — '.$appt->booking_ref, $doctorName.' advised '.($appt->patient?->user?->name ?? 'the patient').' to call an ambulance.', icon: 'heroicon-o-exclamation-triangle', color: 'danger');
                }
            } catch (Throwable) {
            }
        }
    }
}
