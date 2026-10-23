<?php

namespace App\Services\Consults;

use App\Models\Appointment;
use App\Models\DoctorMatchRequest;
use App\Models\DoctorProfile;
use App\Models\EmergencyContact;
use App\Models\PatientProfile;
use App\Models\TriageSubmission;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Dispatch\ProviderAlerts;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Notifications\ExpoPush;
use App\Services\Notifications\InAppNotifier;
use App\Support\DoctorNowSettings;
use App\Support\StaffNotifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * "Speak to a doctor now" after a doctor has the consult:
 *
 *  - VIDEO: ring() puts the patient into an "incoming call" state — a
 *    high-priority push ("Dr X is calling you — tap to join") plus a
 *    cache flag the patient's app / browser polls cheaply. The patient's
 *    screen rings and auto-joins the in-app call. Ringing stops when the
 *    patient joins (answer), declines, or after doctor_now_ring_seconds.
 *  - CALL BACK: the doctor's call card (phone, WhatsApp, triage summary),
 *    "I'm calling now" (pushes the patient), outcomes (done / no answer /
 *    escalate to ambulance) and "start video instead". Every step is
 *    timestamped on the appointment for SLA reporting.
 *
 * Every public method checks that the caller is the appointment's own
 * patient / doctor; callers map DoctorMatchException to an HTTP status.
 */
class ConsultCallService
{
    protected const RING_KEY = 'consult:ring:';

    protected const ACTIVE = [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN];

    // ------------------------------------------------------------------
    // Phone numbers
    // ------------------------------------------------------------------

    /**
     * E.164 digits without "+" (what wa.me wants). South African local
     * numbers (0XX XXX XXXX) become 27XX…; "+27…" / "0027…" are kept.
     */
    public static function normalisePhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! str_starts_with($raw, '+') && str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '27'.substr($digits, 1);
        } elseif (! str_starts_with($raw, '+') && strlen($digits) === 9 && ! str_starts_with($digits, '0')) {
            $digits = '27'.$digits;
        }
        if (str_starts_with($digits, '270') && strlen($digits) === 12) {
            $digits = '27'.substr($digits, 3); // "+27 0821234567"
        }

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
    }

    /** 27821234567 → 082 123 4567 (other countries: +<digits>). */
    public static function displayPhone(?string $digits): ?string
    {
        if (! $digits) {
            return null;
        }
        if (str_starts_with($digits, '27') && strlen($digits) === 11) {
            $local = '0'.substr($digits, 2);

            return substr($local, 0, 3).' '.substr($local, 3, 3).' '.substr($local, 6);
        }

        return '+'.$digits;
    }

    public static function telUrl(?string $digits): ?string
    {
        return $digits ? 'tel:+'.$digits : null;
    }

    /**
     * WhatsApp has no public link that starts a voice call directly: this
     * opens the chat with the patient, where the doctor taps the call icon.
     */
    public static function whatsappUrl(?string $digits): ?string
    {
        return $digits ? 'https://wa.me/'.$digits : null;
    }

    public static function channelLabel(?string $channel): string
    {
        return match ($channel) {
            'whatsapp' => 'WhatsApp call',
            'phone' => 'Normal phone call',
            default => 'Phone or WhatsApp call',
        };
    }

    // ------------------------------------------------------------------
    // Triage + patient brief (what the doctor sees in the alert)
    // ------------------------------------------------------------------

    /**
     * The triage result behind a request: the reference the client passed
     * (the patient's own, or an anonymous one from the last 6 hours, which
     * is then claimed), else the patient's latest red/orange result from
     * the last 2 hours.
     */
    public function resolveTriage(PatientProfile $patient, ?string $reference): ?TriageSubmission
    {
        $userId = (int) $patient->user_id;
        $reference = trim((string) $reference);

        if ($reference !== '') {
            $t = TriageSubmission::query()->where('reference', $reference)->where('created_at', '>=', now()->subHours(6))->first();
            if ($t && ($t->user_id === null || (int) $t->user_id === $userId)) {
                if ($t->user_id === null) {
                    $t->forceFill(['user_id' => $userId])->saveQuietly();
                }

                return $t;
            }
        }

        return TriageSubmission::query()
            ->where('user_id', $userId)
            ->whereIn('level', [TriageSubmission::LEVEL_RED, TriageSubmission::LEVEL_ORANGE])
            ->where('created_at', '>=', now()->subHours(2))
            ->latest('id')
            ->first();
    }

    public static function humanize(string $id): string
    {
        $s = trim(str_replace(['_', '-'], ' ', $id));

        return $s === '' ? $s : mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    /** @return array<string, mixed>|null */
    public function triageSummary(?TriageSubmission $t): ?array
    {
        if (! $t) {
            return null;
        }
        $list = fn ($v) => array_values(array_filter(array_map(fn ($x) => is_string($x) ? self::humanize($x) : null, (array) $v)));
        $reasons = array_values(array_filter(array_map(fn ($x) => is_string($x) ? trim($x) : null, (array) $t->reasons)));

        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'level' => $t->level,
            'level_label' => match ($t->level) {
                'red' => 'RED — emergency',
                'orange' => 'ORANGE — very urgent',
                'yellow' => 'YELLOW — urgent',
                default => 'GREEN — routine',
            },
            'age_band' => $t->age_band,
            'pregnant' => (bool) $t->pregnant,
            'symptoms' => $list($t->symptoms),
            'discriminators' => $list($t->discriminators),
            'reasons' => $reasons,
            'assessed_at' => $t->created_at?->toIso8601String(),
        ];
    }

    /** @return array{name: string, first_name: string, age: ?int, sex: ?string} */
    public function patientBrief(?PatientProfile $p): array
    {
        $name = (string) ($p?->user?->name ?? 'Patient');

        return [
            'name' => $name,
            'first_name' => trim(strtok($name, ' ') ?: $name),
            'age' => $p?->dob ? (int) $p->dob->age : null,
            'sex' => $p?->gender ? ucfirst((string) $p->gender) : null,
        ];
    }

    /**
     * Short lines for the provider alert (web overlay + app modal):
     * age/sex, triage level, presenting symptoms, call-back preference.
     *
     * @return array<int, string>
     */
    public function alertLines(?PatientProfile $p, ?TriageSubmission $t, ?Appointment $appt, string $mode): array
    {
        $brief = $this->patientBrief($p);
        $lines = [];
        $who = array_filter([$brief['age'] !== null ? $brief['age'].' yrs' : ($t?->age_band ? self::humanize((string) $t->age_band) : null), $brief['sex'], $t?->pregnant ? 'Pregnant' : null]);
        if ($who) {
            $lines[] = 'Patient: '.$brief['first_name'].' · '.implode(' · ', $who);
        }
        if ($summary = $this->triageSummary($t)) {
            $lines[] = 'Triage: '.$summary['level_label'];
            $what = array_slice(array_unique(array_merge($summary['symptoms'], $summary['discriminators'])), 0, 6);
            if ($what) {
                $lines[] = 'Presenting: '.implode(', ', $what);
            } elseif ($summary['reasons']) {
                $lines[] = 'Why: '.implode('; ', array_slice($summary['reasons'], 0, 3));
            }
        }
        if ($mode === Appointment::MODE_CALLBACK && $appt) {
            $lines[] = 'Wants a call back: '.self::channelLabel($appt->callback_channel);
            if ($appt->callback_note) {
                $lines[] = 'Note: '.mb_substr((string) $appt->callback_note, 0, 160);
            }
        }

        return $lines;
    }

    // ------------------------------------------------------------------
    // Incoming video call ("ring") for the patient
    // ------------------------------------------------------------------

    public static function ringSeconds(): int
    {
        return max(30, min(900, DoctorNowSettings::int('doctor_now_ring_seconds')));
    }

    public static function autoJoinSeconds(): int
    {
        return max(0, min(30, DoctorNowSettings::int('doctor_now_autojoin_seconds')));
    }

    /**
     * Ring the patient: the doctor accepted an on-demand video consult or
     * pressed Start on a booked one. Re-ringing within 45 s (page reloads)
     * only refreshes the flag — no second push. Returns false when the
     * appointment can't be joined right now.
     */
    public function ring(Appointment $appt, bool $force = false, string $channel = 'video'): bool
    {
        $appt->loadMissing(['patient.user', 'doctor.user']);
        if (! $appt->isVideo() || ! $appt->doctor_profile_id || ! in_array($appt->status, self::ACTIVE, true) || ! $appt->canJoinVideoNow()) {
            return false;
        }
        $patientUser = $appt->patient?->user;
        if (! $patientUser) {
            return false;
        }
        $channel = $channel === 'voice' ? 'voice' : 'video';

        // Consult hub: the ring carries the channel (video | in-app voice).
        $session = app(ConsultHubService::class)->session($appt);
        if ($session->ended_at) {
            return false;
        }
        $channelChanged = $session->ring_channel !== null && $session->ring_channel !== $channel;
        if ($session->ring_channel !== $channel) {
            $session->forceFill(['ring_channel' => $channel])->save();
        }

        $recent = $appt->call_started_at && $appt->call_started_at->gte(now()->subSeconds(45))
            && ! $appt->ring_answered_at && ! $appt->ring_declined_at && ! $channelChanged;

        if (! $recent || $force) {
            $appt->forceFill(['call_started_at' => now(), 'ring_answered_at' => null, 'ring_declined_at' => null])->saveQuietly();
        }
        Cache::put(self::RING_KEY.$patientUser->id, $appt->id, self::ringSeconds());

        if (! $recent || $force) {
            $doctorName = $appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : 'Your doctor';
            $voice = $channel === 'voice';
            $data = [
                'type' => 'consult_call',
                'route' => 'video_call',
                'appointment_id' => $appt->id,
                'params' => ['appointment_id' => $appt->id, 'channel' => $channel],
                'channel' => $channel,
                'doctor_name' => $doctorName,
                'url' => ConsultHubService::patientHubUrl($appt) ? '/account/consults/'.$appt->id.'?join='.$channel : '/account/appointments/'.$appt->id.'/call',
            ];
            $title = $doctorName.' is calling you'.($voice ? ' (voice)' : ' (video)');
            $body = $voice ? 'Tap to answer your voice call in the app — it uses data, not airtime.' : 'Tap to join your video consult now.';
            try {
                if (InAppNotifier::enabled('appointment')) {
                    UserNotification::query()->create([
                        'user_id' => $patientUser->id, 'type' => 'appointment',
                        'title' => $title, 'body' => $body, 'data' => $data,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[ConsultCall] feed row failed: '.$e->getMessage());
            }
            ExpoPush::toUsers($patientUser->id, $title, $body, $data, ProviderAlerts::PUSH_CHANNEL, ProviderAlerts::PUSH_SOUND);
        }

        return true;
    }

    /** Is this appointment ringing for its patient right now? */
    public function isRinging(Appointment $appt): bool
    {
        if (! $appt->isVideo() || ! $appt->call_started_at || ! in_array($appt->status, self::ACTIVE, true)) {
            return false;
        }
        $started = $appt->call_started_at;
        if ($started->lt(now()->subSeconds(self::ringSeconds()))) {
            return false;
        }
        foreach ([$appt->ring_answered_at, $appt->ring_declined_at, $appt->video_ended_at] as $t) {
            if ($t && $t->gte($started)) {
                return false;
            }
        }

        return $appt->canJoinVideoNow();
    }

    /** @return array<string, mixed>|null */
    public function ringPayload(Appointment $appt): ?array
    {
        if (! $this->isRinging($appt)) {
            return null;
        }
        $appt->loadMissing('doctor.user');
        $doctor = $appt->doctor;
        $ends = $appt->call_started_at->copy()->addSeconds(self::ringSeconds());

        $channel = app(ConsultHubService::class)->existingSession($appt)?->ring_channel ?: 'video';
        $hubUrl = ConsultHubService::patientHubUrl($appt);

        return [
            'appointment_id' => $appt->id,
            'mode' => 'video',
            'channel' => $channel,
            'doctor_name' => $doctor?->user ? 'Dr. '.$doctor->user->name : 'Your doctor',
            'doctor_specialization' => $doctor?->specialization,
            'doctor_photo_url' => \App\Support\Widgets\WidgetDataService::mediaUrl($doctor?->user?->avatar),
            'call_url' => Route::has('account.appointments.call') ? route('account.appointments.call', $appt).($channel === 'voice' ? '?audio=1' : '') : null,
            'hub_url' => $hubUrl ? $hubUrl.'?join='.$channel : null,
            'auto_join_seconds' => self::autoJoinSeconds(),
            'started_at' => $appt->call_started_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($ends, false)),
        ];
    }

    /**
     * Cheap check for the patient's global poller (app + website): a cache
     * hit is needed before the database is touched.
     *
     * @return array<string, mixed>|null
     */
    public function ringFor(User $patient): ?array
    {
        $id = Cache::get(self::RING_KEY.$patient->id);
        if (! $id) {
            return null;
        }
        $appt = Appointment::query()->with('doctor.user', 'patient')->find((int) $id);
        if (! $appt || (int) $appt->patient?->user_id !== (int) $patient->id) {
            Cache::forget(self::RING_KEY.$patient->id);

            return null;
        }
        $payload = $this->ringPayload($appt);
        if (! $payload) {
            Cache::forget(self::RING_KEY.$patient->id);
        }

        return $payload;
    }

    protected function ownAsPatient(Appointment $appt, User $user): void
    {
        $appt->loadMissing('patient');
        if (! $appt->patient || (int) $appt->patient->user_id !== (int) $user->id) {
            throw new DoctorMatchException('Appointment not found.', 404);
        }
    }

    /** The patient joined (opening the call page / join payload counts). */
    public function answer(Appointment $appt, User $patient): void
    {
        $this->ownAsPatient($appt, $patient);
        if ($appt->call_started_at && ! $appt->ring_answered_at) {
            $appt->forceFill(['ring_answered_at' => now()])->saveQuietly();
        }
        Cache::forget(self::RING_KEY.$patient->id);
    }

    public function decline(Appointment $appt, User $patient): void
    {
        $this->ownAsPatient($appt, $patient);
        $appt->forceFill(['ring_declined_at' => now()])->saveQuietly();
        Cache::forget(self::RING_KEY.$patient->id);

        $doctorUser = $appt->doctor?->user;
        if ($doctorUser) {
            $title = 'Patient can\'t join right now';
            $body = ($appt->patient?->user?->name ?? 'The patient').' declined the video call. You can message them or try again.';
            app(InAppNotifier::class)->send($doctorUser, 'appointment', $title, $body, ['route' => 'doctor_appointment', 'params' => ['appointment_id' => $appt->id]]);
            StaffNotifier::alert($doctorUser, $title, $body, icon: 'heroicon-o-phone-x-mark', color: 'warning');
        }
    }

    // ------------------------------------------------------------------
    // Call back
    // ------------------------------------------------------------------

    public static function targetMinutes(): int
    {
        return max(1, min(240, DoctorNowSettings::int('doctor_now_callback_target_minutes')));
    }

    protected function ownAsDoctor(Appointment $appt, DoctorProfile $doctor): void
    {
        if ((int) $appt->doctor_profile_id !== (int) $doctor->id) {
            throw new DoctorMatchException('Appointment not found.', 404);
        }
    }

    protected function requireOpenCallback(Appointment $appt): void
    {
        if (! $appt->isCallback()) {
            throw new DoctorMatchException('This is not a call-back consult.', 422);
        }
        if (! in_array($appt->status, self::ACTIVE, true)) {
            throw new DoctorMatchException('This consult is already closed.', 409);
        }
    }

    /** Number to call: what the patient entered, else their profile phone. */
    public function callbackDigits(Appointment $appt): ?string
    {
        $appt->loadMissing('patient.user');

        return self::normalisePhone($appt->callback_phone) ?? self::normalisePhone($appt->patient?->user?->phone);
    }

    /** Accepted-at for an on-demand consult, else the booked start (SA time). */
    public function callbackBase(Appointment $appt): ?Carbon
    {
        $matched = DoctorMatchRequest::query()->where('appointment_id', $appt->id)->value('matched_at');
        if ($matched) {
            return Carbon::parse($matched);
        }
        if ($appt->date && $appt->start_time) {
            return Carbon::parse($appt->date->toDateString().' '.$appt->start_time, Appointment::CLINIC_TIMEZONE)->utc();
        }

        return null;
    }

    /**
     * Call-back details. The patient's own view omits nothing sensitive
     * (it's their number); doctors get tel:/wa.me links.
     *
     * @return array<string, mixed>|null
     */
    public function callbackPayload(Appointment $appt, bool $forDoctor = false): ?array
    {
        if (! $appt->isCallback()) {
            return null;
        }
        $digits = $this->callbackDigits($appt);
        $base = $this->callbackBase($appt);
        $due = $base?->copy()->addMinutes(self::targetMinutes());

        $out = [
            'phone' => $digits ? '+'.$digits : null,
            'phone_display' => self::displayPhone($digits),
            'channel' => $appt->callback_channel ?: 'either',
            'channel_label' => self::channelLabel($appt->callback_channel),
            'note' => $appt->callback_note,
            'target_minutes' => self::targetMinutes(),
            'due_at' => $due?->toIso8601String(),
            'overdue' => (bool) ($due && ! $appt->called_at && $due->isPast() && in_array($appt->status, self::ACTIVE, true)),
            'called_at' => $appt->called_at?->toIso8601String(),
            'call_attempts' => (int) $appt->call_attempts,
            'outcome' => $appt->call_outcome,
            'outcome_at' => $appt->call_outcome_at?->toIso8601String(),
        ];
        if ($forDoctor) {
            $out['tel_url'] = self::telUrl($digits);
            $out['whatsapp_url'] = self::whatsappUrl($digits);
            $out['whatsapp_note'] = 'WhatsApp has no link that starts a call directly: this opens the chat — tap the phone icon there to call.';
        }

        return $out;
    }

    /**
     * Everything the doctor's consult card needs (Filament CallbackConsult
     * page and the app's ConsultCard screen).
     *
     * @return array<string, mixed>
     */
    public function doctorCard(Appointment $appt, DoctorProfile $doctor): array
    {
        $this->ownAsDoctor($appt, $doctor);
        $appt->loadMissing(['patient.user', 'triageSubmission']);
        $triage = $appt->triageSubmission;
        if (! $triage) {
            $tid = DoctorMatchRequest::query()->where('appointment_id', $appt->id)->value('triage_submission_id');
            $triage = $tid ? TriageSubmission::query()->find($tid) : null;
        }
        $p = $appt->patient;

        return [
            'appointment_id' => $appt->id,
            'booking_ref' => $appt->booking_ref,
            'mode' => $appt->isCallback() ? Appointment::MODE_CALLBACK : ($appt->isVideo() ? Appointment::MODE_VIDEO : Appointment::MODE_IN_PERSON),
            'status' => $appt->status,
            'is_open' => in_array($appt->status, self::ACTIVE, true),
            'reason' => $appt->reason,
            'when' => trim(($appt->date?->format('D d M') ?? '').' '.substr((string) $appt->start_time, 0, 5)),
            'patient' => $this->patientBrief($p) + [
                'allergies' => $p?->allergies,
                'chronic_conditions' => $p?->chronic_conditions,
            ],
            'triage' => $this->triageSummary($triage),
            'callback' => $this->callbackPayload($appt, true),
            'can_start_video' => $appt->isCallback() && in_array($appt->status, self::ACTIVE, true),
            'video_call_url' => $appt->isVideo() && class_exists(\App\Filament\Pages\ConsultCall::class)
                ? \App\Filament\Pages\ConsultCall::getUrl(['appointment' => $appt->id], panel: 'admin') : null,
            'ringing' => $this->isRinging($appt),
            'hub_url' => ConsultHubService::doctorHubUrl($appt),
        ];
    }

    /** Doctor tapped Call / WhatsApp: stamp the SLA time and tell the patient to pick up. */
    public function markCalling(Appointment $appt, DoctorProfile $doctor, ?string $via = null): Appointment
    {
        $this->ownAsDoctor($appt, $doctor);
        $this->requireOpenCallback($appt);

        $appt->forceFill([
            'called_at' => $appt->called_at ?? now(),
            'call_attempts' => (int) $appt->call_attempts + 1,
            'last_call_attempt_at' => now(), // missed call-back fee rule (attempt span)
        ])->saveQuietly();

        $patientUser = $appt->patient?->user;
        if ($patientUser) {
            $doctorName = $doctor->user ? 'Dr. '.$doctor->user->name : 'Your doctor';
            $how = $via === 'whatsapp' ? 'on WhatsApp' : ($via === 'phone' ? 'by phone' : 'now');
            $phone = self::displayPhone($this->callbackDigits($appt));
            $data = ['type' => 'consult_callback', 'route' => 'appointment', 'appointment_id' => $appt->id, 'params' => ['appointment_id' => $appt->id], 'url' => '/account/see-a-doctor'];
            try {
                if (InAppNotifier::enabled('appointment')) {
                    UserNotification::query()->create(['user_id' => $patientUser->id, 'type' => 'appointment', 'title' => $doctorName.' is calling you', 'body' => 'Answer your phone'.($phone ? ' ('.$phone.')' : '').' — the doctor is calling you '.$how.'.', 'data' => $data]);
                }
            } catch (\Throwable) {
            }
            ExpoPush::toUsers($patientUser->id, $doctorName.' is calling you', 'Answer your phone'.($phone ? ' ('.$phone.')' : '').' — the doctor is calling you '.$how.'.', $data, ProviderAlerts::PUSH_CHANNEL, ProviderAlerts::PUSH_SOUND);
        }

        return $appt->fresh();
    }

    public function recordOutcome(Appointment $appt, DoctorProfile $doctor, string $outcome): Appointment
    {
        $this->ownAsDoctor($appt, $doctor);
        $this->requireOpenCallback($appt);
        if (! in_array($outcome, Appointment::CALL_OUTCOMES, true)) {
            throw new DoctorMatchException('Unknown outcome.', 422);
        }

        $now = now();
        $attrs = ['call_outcome' => $outcome, 'call_outcome_at' => $now, 'called_at' => $appt->called_at ?? $now];
        if ($outcome === 'no_answer') {
            $appt->forceFill($attrs)->saveQuietly();
        } else {
            // Consult hub record: closes the session and fires ConsultEnded once.
            try {
                if ($doctorUser = $doctor->user ?? $appt->doctor?->user) {
                    app(ConsultHubService::class)->closeFromCallbackOutcome($appt, $doctorUser, $outcome);
                }
            } catch (\Throwable $e) {
                Log::warning('[ConsultCall] hub close failed: '.$e->getMessage());
            }
            $appt->forceFill($attrs);
            // Status change goes through the model so the observer releases the doctor.
            $appt->status = Appointment::STATUS_COMPLETED;
            $appt->save();
        }

        $patientUser = $appt->patient?->user;
        $doctorName = $doctor->user ? 'Dr. '.$doctor->user->name : 'Your doctor';
        if ($patientUser) {
            $phone = self::displayPhone($this->callbackDigits($appt));
            $data = ['type' => 'consult_callback', 'route' => 'appointment', 'appointment_id' => $appt->id, 'params' => ['appointment_id' => $appt->id], 'url' => '/account/appointments'];
            [$title, $body] = match ($outcome) {
                'no_answer' => [$doctorName.' tried to call you', 'We couldn\'t reach you'.($phone ? ' on '.$phone : '').'. Keep your phone close — the doctor will try again.'],
                'escalated' => ['Please call an ambulance now', $doctorName.' advises you to get emergency care now. Call '.(EmergencyContact::primary()?->phone ?: '10177').' or 112 from a mobile.'],
                default => ['Consult complete', 'Your call with '.$doctorName.' is complete. Thank you for using Prompt Aid.'],
            };
            if ($outcome === 'escalated' && ($ec = EmergencyContact::primary())) {
                $data['tel_url'] = $ec->tel_url;
            }
            app(InAppNotifier::class)->send($patientUser, 'appointment', $title, $body, $data);
        }

        if ($outcome === 'escalated') {
            try {
                $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();
                if ($admins->isNotEmpty()) {
                    StaffNotifier::alert($admins, 'Call-back escalated to ambulance — '.$appt->booking_ref, $doctorName.' advised '.($patientUser?->name ?? 'the patient').' to call an ambulance.', icon: 'heroicon-o-exclamation-triangle', color: 'danger');
                }
            } catch (\Throwable) {
            }
        }

        return $appt->fresh();
    }

    /** Doctor prefers video after all: the consult becomes a video consult and the patient's phone rings in-app. */
    public function switchToVideo(Appointment $appt, DoctorProfile $doctor): Appointment
    {
        $this->ownAsDoctor($appt, $doctor);
        $this->requireOpenCallback($appt);

        $appt->mode = Appointment::MODE_VIDEO;
        $appt->visit_type = 'telemed';
        $appt->save(); // saving hook generates the video room
        DoctorMatchRequest::query()->where('appointment_id', $appt->id)->update(['mode' => Appointment::MODE_VIDEO, 'updated_at' => now()]);

        $appt = $appt->fresh();
        $this->ring($appt, true);

        return $appt->fresh();
    }
}
