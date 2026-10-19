<?php

namespace App\Services\Doctors;

use App\Contracts\GeocodingInterface;
use App\Contracts\NotificationDispatcherInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Models\DoctorMatchWaitlist;
use App\Models\DoctorProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientProfile;
use App\Models\Payment;
use App\Services\Consults\ConsultCallService;
use App\Services\Dispatch\ProviderAlerts;
use App\Support\DispatchSettings;
use App\Support\DoctorNowSettings;
use App\Support\StaffNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Uber-style" on-demand doctor matching ("See a doctor now"), video or
 * in-person. Additive to AppointmentBookingService's browse-and-pick-a-slot
 * flow — both produce the same Appointment shape.
 *
 * Lifecycle (one DoctorMatchRequest per Appointment):
 *   request → offer to the best online doctor (one at a time, timeout) →
 *   accept ⇒ appointment confirmed, doctor busy, invoice raised at the price
 *   locked at request time | decline / timeout ⇒ next doctor … until
 *   max attempts or the overall request timeout ⇒ expired (appointment
 *   cancelled with a reason, nothing charged).
 *
 * No queue worker on shared hosting, so progression is lazy: progress()
 * runs whenever the patient or a doctor polls, and tick() (registered as a
 * background job / console command) sweeps everything else.
 */
class DoctorMatchService
{
    // No fixed slot exists for an on-demand consult, so the appointment's
    // date/start/end are stamped to "now" for this many minutes.
    protected const DEFAULT_DURATION_MINUTES = 30;

    public function __construct(
        protected GeocodingInterface $geo,
        protected NotificationDispatcherInterface $notifier,
        protected DoctorMatchPricing $pricing,
        protected PaymentGatewayInterface $gateway,
    ) {}

    // ------------------------------------------------------------------
    // Presence
    // ------------------------------------------------------------------

    /** Doctors who can be offered a request right now (online, fresh heartbeat, not busy). */
    public static function onlineQuery(?string $specialty = null): Builder
    {
        $stale = max(30, DoctorNowSettings::int('doctor_now_heartbeat_stale_seconds'));

        $query = DoctorProfile::query()
            ->where('status', 'active')
            ->where('is_accepting_appointments', true)
            ->where('availability', DoctorProfile::AVAILABLE)
            ->where('last_seen_at', '>=', now()->subSeconds($stale));

        if ($specialty !== null) {
            $query->where('specialization', $specialty);
        }

        return $query;
    }

    /** @return array<int, string> distinct specialties of active, bookable doctors */
    public static function specialties(): array
    {
        return DoctorProfile::query()
            ->where('status', 'active')
            ->whereNotNull('specialization')
            ->where('specialization', '!=', '')
            ->distinct()
            ->orderBy('specialization')
            ->pluck('specialization')
            ->map(fn ($s) => trim((string) $s))
            ->unique()
            ->values()
            ->all();
    }

    public function isFresh(DoctorProfile $doctor): bool
    {
        $stale = max(30, DoctorNowSettings::int('doctor_now_heartbeat_stale_seconds'));

        return $doctor->last_seen_at !== null && $doctor->last_seen_at->gte(now()->subSeconds($stale));
    }

    /** Console heartbeat: keeps the doctor "fresh" and optionally updates their location. */
    public function heartbeat(DoctorProfile $doctor, ?float $lat = null, ?float $lng = null): void
    {
        $wasFresh = $this->isFresh($doctor);

        $attrs = ['last_seen_at' => now()];
        if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
            $attrs += ['current_lat' => $lat, 'current_lng' => $lng, 'location_updated_at' => now()];
        }

        // Quiet save: a heartbeat every 20s must not flood the audit log.
        $doctor->forceFill($attrs)->saveQuietly();

        if (! $wasFresh && $doctor->availability === DoctorProfile::AVAILABLE) {
            $this->notifyWaitlist($doctor);
        }

        $this->tick();
    }

    /** online / offline toggle from the console or the app. */
    public function setAvailability(DoctorProfile $doctor, string $availability): DoctorProfile
    {
        // BUSY = "orange" for patients: available today at a booked slot,
        // but not offered instant "see a doctor now" requests.
        if (! in_array($availability, [DoctorProfile::AVAILABLE, DoctorProfile::BUSY, DoctorProfile::OFFLINE], true)) {
            throw new DoctorMatchException('Unknown availability.');
        }

        if ($availability === DoctorProfile::AVAILABLE && $this->activeConsultFor($doctor)) {
            throw new DoctorMatchException('Finish your current consult before going online again.', 409);
        }

        $doctor->update(['availability' => $availability]);
        $doctor->forceFill(['last_seen_at' => now()])->saveQuietly();

        if ($availability !== DoctorProfile::AVAILABLE) {
            // Hand any live offer straight on to the next doctor.
            DoctorMatchOffer::query()
                ->where('doctor_profile_id', $doctor->id)
                ->where('status', DoctorMatchOffer::OFFERED)
                ->get()
                ->each(function (DoctorMatchOffer $offer) {
                    $offer->update(['status' => DoctorMatchOffer::DECLINED, 'responded_at' => now()]);
                    if ($offer->matchRequest) {
                        $this->progress($offer->matchRequest);
                    }
                });
        } else {
            $this->notifyWaitlist($doctor);
            $this->tick(force: true);
        }

        return $doctor->fresh();
    }

    // ------------------------------------------------------------------
    // Patient: request / cancel / waitlist
    // ------------------------------------------------------------------

    /**
     * Back-compat entry point (POST /api/request-doctor): starts the offer
     * engine and returns the appointment. $autoAssign is ignored — doctors
     * now accept/decline offers.
     */
    public function requestMatch(
        PatientProfile $patient,
        string $specialty,
        string $mode,
        ?float $lat = null,
        ?float $lng = null,
        bool $autoAssign = true,
    ): Appointment {
        return $this->start($patient, $specialty, $mode, $lat, $lng)->appointment()->first();
    }

    /** "GP / general" unless the patient chose a specialty: the first General… specialty, else the first one. */
    public static function defaultSpecialty(): ?string
    {
        $all = self::specialties();
        foreach ($all as $s) {
            if (preg_match('/general|\bgp\b|family/i', $s)) {
                return $s;
            }
        }

        return $all[0] ?? null;
    }

    /** Which "speak to a doctor now" options are switched on (admin → On-demand doctors). @return array<int, string> */
    public static function enabledModes(): array
    {
        return array_values(array_filter([
            DoctorNowSettings::bool('doctor_now_video_enabled') ? Appointment::MODE_VIDEO : null,
            DoctorNowSettings::bool('doctor_now_callback_enabled') ? Appointment::MODE_CALLBACK : null,
            Appointment::MODE_IN_PERSON,
        ]));
    }

    /**
     * @param  array{callback_phone?: ?string, callback_channel?: ?string, callback_note?: ?string, triage_reference?: ?string}  $extra
     */
    public function start(PatientProfile $patient, ?string $specialty, string $mode, ?float $lat = null, ?float $lng = null, ?float $radiusKm = null, array $extra = []): DoctorMatchRequest
    {
        if (! DoctorNowSettings::bool('doctor_now_enabled')) {
            throw new DoctorMatchException('On-demand consultations are switched off right now. Please book a time slot instead.', 409);
        }

        $specialty = trim((string) $specialty);
        if ($specialty === '') {
            $specialty = (string) self::defaultSpecialty();
        }
        if (! in_array($mode, [Appointment::MODE_VIDEO, Appointment::MODE_IN_PERSON, Appointment::MODE_CALLBACK], true)) {
            throw new DoctorMatchException('Choose a video consult, a call back or in person.');
        }
        if (! in_array($mode, self::enabledModes(), true)) {
            throw new DoctorMatchException($mode === Appointment::MODE_CALLBACK
                ? 'Call backs are not available right now. Please choose a video consult.'
                : 'Video consults are not available right now. Please request a call back.', 409);
        }
        if (! in_array(mb_strtolower($specialty), array_map('mb_strtolower', self::specialties()), true)) {
            throw new DoctorMatchException('We don\'t have doctors in that specialty yet.');
        }
        if ($mode === Appointment::MODE_IN_PERSON && ($lat === null || $lng === null)) {
            throw new DoctorMatchException('We need your location to find a doctor\'s rooms near you.');
        }

        $callbackPhone = null;
        $channel = null;
        $note = null;
        if ($mode === Appointment::MODE_CALLBACK) {
            $callbackPhone = ConsultCallService::normalisePhone($extra['callback_phone'] ?? null)
                ?? ConsultCallService::normalisePhone($patient->user?->phone);
            if (! $callbackPhone) {
                throw new DoctorMatchException('Enter the phone number the doctor should call.', 422);
            }
            $channel = in_array($extra['callback_channel'] ?? null, Appointment::CALLBACK_CHANNELS, true) ? $extra['callback_channel'] : 'either';
            $note = trim(strip_tags((string) ($extra['callback_note'] ?? '')));
            $note = $note !== '' ? mb_substr($note, 0, 500) : null;
        }

        $existing = $this->openRequestFor($patient);
        if ($existing) {
            throw new DoctorMatchException('You already have a doctor request in progress.', 409);
        }

        $triage = app(ConsultCallService::class)->resolveTriage($patient, $extra['triage_reference'] ?? null);
        $quote = $this->pricing->quote($specialty, $mode);
        $now = now();

        $request = DB::transaction(function () use ($patient, $specialty, $mode, $lat, $lng, $quote, $now, $radiusKm, $callbackPhone, $channel, $note, $triage) {
            $appointment = Appointment::query()->create([
                'patient_profile_id' => $patient->id,
                // Appointment dates/times are South African clinic time (see Appointment::CLINIC_TIMEZONE).
                'doctor_profile_id' => null,
                'clinic_id' => null,
                'date' => $now->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->toDateString(),
                'start_time' => $now->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->format('H:i:s'),
                'end_time' => $now->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->addMinutes(self::DEFAULT_DURATION_MINUTES)->format('H:i:s'),
                // visit_type predates the mode column; map the closest legacy value.
                'visit_type' => match ($mode) {
                    Appointment::MODE_VIDEO => 'telemed',
                    Appointment::MODE_CALLBACK => Appointment::VISIT_PHONE,
                    default => 'clinic',
                },
                'status' => Appointment::STATUS_MATCHING,
                'mode' => $mode,
                'requested_specialty' => $specialty,
                'reason' => $mode === Appointment::MODE_CALLBACK ? 'Doctor call back requested' : 'See a doctor now',
                'callback_phone' => $callbackPhone,
                'callback_channel' => $channel,
                'callback_note' => $note,
                'triage_submission_id' => $triage?->id,
            ]);

            return DoctorMatchRequest::query()->create([
                'appointment_id' => $appointment->id,
                'patient_profile_id' => $patient->id,
                'specialty' => $specialty,
                'mode' => $mode,
                'lat' => $mode === Appointment::MODE_IN_PERSON ? $lat : null,
                'lng' => $mode === Appointment::MODE_IN_PERSON ? $lng : null,
                'status' => DoctorMatchRequest::SEARCHING,
                'base_fee' => $quote['base_fee'],
                'multiplier' => $quote['multiplier'],
                'price' => $quote['price'],
                'price_breakdown' => $quote,
                'attempts' => 0,
                'expires_at' => $now->clone()->addMinutes(max(1, DoctorNowSettings::int('doctor_now_request_timeout_minutes'))),
                'radius_km' => $radiusKm !== null && $mode === Appointment::MODE_IN_PERSON ? DispatchSettings::clampRadius($radiusKm) : null,
                'triage_submission_id' => $triage?->id,
            ]);
        });

        $this->progress($request);

        return $request->fresh();
    }

    public function openRequestFor(PatientProfile $patient): ?DoctorMatchRequest
    {
        return DoctorMatchRequest::query()
            ->where('patient_profile_id', $patient->id)
            ->where('status', DoctorMatchRequest::SEARCHING)
            ->latest('id')
            ->first();
    }

    /** The request the patient's "See a doctor now" page should resume: searching, or matched and not finished. */
    public function currentRequestFor(PatientProfile $patient): ?DoctorMatchRequest
    {
        return DoctorMatchRequest::query()
            ->where('patient_profile_id', $patient->id)
            ->where(function ($q) {
                $q->where('status', DoctorMatchRequest::SEARCHING)
                    ->orWhere(function ($m) {
                        $m->where('status', DoctorMatchRequest::MATCHED)
                            ->whereHas('appointment', fn ($a) => $a->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN]))
                            ->where('matched_at', '>=', now()->subHours(6));
                    });
            })
            ->latest('id')
            ->first();
    }

    /** Patient cancel: free while searching; after accept the admin fee rule applies. */
    public function cancelByPatient(DoctorMatchRequest $request, ?string $reason = null): DoctorMatchRequest
    {
        $request->refresh();

        if ($request->status === DoctorMatchRequest::SEARCHING) {
            DB::transaction(function () use ($request) {
                $locked = DoctorMatchRequest::query()->whereKey($request->id)->lockForUpdate()->first();
                if ($locked->status !== DoctorMatchRequest::SEARCHING) {
                    return;
                }
                $this->closeLiveOffers($locked);
                $locked->update([
                    'status' => DoctorMatchRequest::CANCELLED,
                    'outcome_reason' => 'cancelled_by_patient',
                    'cancelled_at' => now(),
                ]);
                $locked->appointment?->update([
                    'status' => Appointment::STATUS_CANCELLED,
                    'cancel_reason' => 'Cancelled by patient before a doctor accepted',
                ]);
            });

            $request->refresh();
            if ($request->status === DoctorMatchRequest::MATCHED) {
                // A doctor accepted in the same instant — fall through to the post-accept rule.
                return $this->cancelByPatient($request, $reason);
            }

            return $request;
        }

        if ($request->status === DoctorMatchRequest::MATCHED) {
            $appointment = $request->appointment;
            if (! $appointment || ! in_array($appointment->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
                throw new DoctorMatchException('This consult can no longer be cancelled.', 409);
            }

            $this->settleCancellation($request, byPatient: true, reason: $reason ?: 'Cancelled by patient');
            // The observer sees the request is already settled and only releases the doctor.
            $appointment->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => $reason ?: 'Cancelled by patient']);

            return $request->fresh();
        }

        throw new DoctorMatchException('This request is already closed.', 409);
    }

    /**
     * Applies the cancellation money rule to a matched request and marks it
     * cancelled. Patient cancels inside the free window, or any doctor/admin
     * cancel, cost nothing (refund if paid); otherwise the admin fee.
     */
    public function settleCancellation(DoctorMatchRequest $request, bool $byPatient, string $reason): void
    {
        if ($request->status !== DoctorMatchRequest::MATCHED) {
            return;
        }

        $fee = $byPatient ? $this->pricing->cancellationFee($request) : 0.0;

        $request->update([
            'status' => DoctorMatchRequest::CANCELLED,
            'outcome_reason' => $byPatient ? 'cancelled_by_patient_after_accept' : 'cancelled_by_provider',
            'cancelled_at' => now(),
            'cancel_fee' => $fee,
        ]);

        $invoice = $request->invoice;
        if ($invoice) {
            try {
                $this->adjustInvoiceForCancellation($invoice, (float) $request->price, $fee);
            } catch (\Throwable $e) {
                Log::warning('[DoctorMatch] invoice adjust failed: '.$e->getMessage());
            }
        }

        $doctorUser = $request->doctor?->user;
        $patientUser = $request->patient?->user;
        if ($byPatient && $doctorUser) {
            $this->notifier->push($doctorUser, 'Consult cancelled', "{$patientUser?->name} cancelled the on-demand consult. You're free for the next request.", [
                'type' => 'appointment', 'data' => ['route' => 'doctor_appointment', 'params' => ['appointment_id' => $request->appointment_id]],
            ]);
        } elseif (! $byPatient && $patientUser) {
            $this->notifier->push($patientUser, 'Consult cancelled', 'Your on-demand consult was cancelled by the practice. You have not been charged.', [
                'type' => 'appointment', 'data' => ['route' => 'appointment', 'params' => ['appointment_id' => $request->appointment_id], 'url' => '/account/see-a-doctor'],
            ]);
        }
    }

    protected function adjustInvoiceForCancellation(Invoice $invoice, float $price, float $fee): void
    {
        $paidPayment = $invoice->payments()->where('status', 'success')->latest('id')->first();

        if (! $paidPayment) {
            if ($fee <= 0) {
                // Never paid and nothing owed: remove the bill entirely.
                $invoice->items()->delete();
                $invoice->delete();

                return;
            }
            $invoice->items()->delete();
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'description' => 'Cancellation fee — on-demand consult cancelled after the doctor accepted',
                'qty' => 1, 'unit_price' => $fee, 'amount' => $fee,
            ]);
            $invoice->update(['subtotal' => $fee, 'total' => $fee]);

            return;
        }

        $refund = round($price - $fee, 2);
        if ($refund <= 0) {
            return;
        }

        $result = $this->gateway->refund((string) $paidPayment->gateway_ref, $refund);
        if (! ($result['success'] ?? false)) {
            Log::warning('[DoctorMatch] refund failed for invoice '.$invoice->id.': '.($result['message'] ?? ''));

            return;
        }

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'description' => $fee > 0 ? 'Refund less cancellation fee' : 'Refund — consult cancelled',
            'qty' => 1, 'unit_price' => -$refund, 'amount' => -$refund,
        ]);
        $invoice->update([
            'subtotal' => $fee,
            'total' => $fee,
            'status' => $fee > 0 ? Invoice::STATUS_PAID : Invoice::STATUS_REFUNDED,
        ]);
    }

    /** "Notify me when a doctor is online" — one in-app ping, then the row is closed. */
    public function joinWaitlist(DoctorMatchRequest $request): DoctorMatchWaitlist
    {
        $hours = max(1, DoctorNowSettings::int('doctor_now_waitlist_hours'));

        $row = DoctorMatchWaitlist::query()
            ->where('patient_profile_id', $request->patient_profile_id)
            ->where('specialty', $request->specialty)
            ->where('mode', $request->mode)
            ->where('status', DoctorMatchWaitlist::WAITING)
            ->first();

        $attrs = [
            'doctor_match_request_id' => $request->id,
            'lat' => $request->lat,
            'lng' => $request->lng,
            'expires_at' => now()->addHours($hours),
        ];

        if ($row) {
            $row->update($attrs);

            return $row;
        }

        return DoctorMatchWaitlist::query()->create($attrs + [
            'patient_profile_id' => $request->patient_profile_id,
            'specialty' => $request->specialty,
            'mode' => $request->mode,
            'status' => DoctorMatchWaitlist::WAITING,
        ]);
    }

    public function isWaitlisted(DoctorMatchRequest $request): bool
    {
        return DoctorMatchWaitlist::query()
            ->where('patient_profile_id', $request->patient_profile_id)
            ->where('specialty', $request->specialty)
            ->where('mode', $request->mode)
            ->where('status', DoctorMatchWaitlist::WAITING)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function notifyWaitlist(DoctorProfile $doctor): int
    {
        if (! $doctor->specialization) {
            return 0;
        }

        $rows = DoctorMatchWaitlist::query()
            ->where('status', DoctorMatchWaitlist::WAITING)
            ->where('specialty', $doctor->specialization)
            ->where('expires_at', '>', now())
            ->with('patient.user')
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($rows as $row) {
            if ($row->patient?->user_id === $doctor->user_id) {
                continue;
            }
            if ($row->mode === Appointment::MODE_IN_PERSON) {
                if ($row->lat === null || $row->lng === null) {
                    continue;
                }
                $loc = $this->doctorRooms($doctor, (float) $row->lat, (float) $row->lng);
                if (! $loc || $loc['distance_km'] > DoctorNowSettings::float('doctor_now_inperson_radius_km')) {
                    continue;
                }
            }

            $updated = DoctorMatchWaitlist::query()->whereKey($row->id)->where('status', DoctorMatchWaitlist::WAITING)
                ->update(['status' => DoctorMatchWaitlist::NOTIFIED, 'notified_at' => now()]);
            if (! $updated || ! $row->patient?->user) {
                continue;
            }

            $modeLabel = $row->mode === Appointment::MODE_VIDEO ? 'video' : 'in-person';
            $this->notifier->push($row->patient->user, 'A doctor is online now', "A {$row->specialty} is available for a {$modeLabel} consult. Request now before they're taken.", [
                'type' => 'appointment',
                'data' => [
                    'route' => 'see_doctor_now',
                    'params' => ['specialty' => $row->specialty, 'mode' => $row->mode],
                    'url' => '/account/see-a-doctor?'.http_build_query(['specialty' => $row->specialty, 'mode' => $row->mode]),
                ],
            ]);
            $sent++;
        }

        return $sent;
    }

    // ------------------------------------------------------------------
    // Offer engine
    // ------------------------------------------------------------------

    /**
     * Move one request forward: expire a lapsed offer, expire the request
     * if it's out of time/attempts, or offer it to the next best doctor.
     */
    public function progress(DoctorMatchRequest $request): void
    {
        $events = DB::transaction(function () use ($request) {
            $events = [];
            $req = DoctorMatchRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $req || $req->status !== DoctorMatchRequest::SEARCHING) {
                return $events;
            }

            if (DispatchSettings::mode('doctor_now') === 'manual') {
                if ($req->expires_at && $req->expires_at->isPast()) {
                    $this->expireLocked($req, 'timed_out');
                    $events[] = ['expired', $req];
                }

                return $events; // an admin assigns from Live requests
            }

            if (DispatchSettings::mode('doctor_now') === 'broadcast') {
                // Uber-style: every qualifying online doctor gets the offer at once.
                DoctorMatchOffer::query()->where('doctor_match_request_id', $req->id)->where('status', DoctorMatchOffer::OFFERED)
                    ->where('expires_at', '<=', now())->update(['status' => DoctorMatchOffer::EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);
                if ($req->expires_at && $req->expires_at->isPast()) {
                    $this->expireLocked($req, 'timed_out');
                    $events[] = ['expired', $req];

                    return $events;
                }
                $timeout = max(10, DoctorNowSettings::int('doctor_now_offer_timeout_seconds'));
                $candidates = $this->pickCandidates($req, true)->take(60);
                if ($candidates->isEmpty() && (int) $req->attempts > 0
                    && ! DoctorMatchOffer::query()->where('doctor_match_request_id', $req->id)->where('status', DoctorMatchOffer::OFFERED)->exists()) {
                    // Every alerted doctor declined or let the alert lapse and nobody new is
                    // online: tell the patient now (retry / call dispatch), not after a silent wait.
                    $this->expireLocked($req, 'no_doctor_accepted');
                    $events[] = ['expired', $req];

                    return $events;
                }
                foreach ($candidates as $candidate) {
                    $offer = DoctorMatchOffer::query()->create([
                        'doctor_match_request_id' => $req->id,
                        'appointment_id' => $req->appointment_id,
                        'doctor_profile_id' => $candidate['doctor']->id,
                        'status' => DoctorMatchOffer::OFFERED,
                        'distance_km' => $candidate['distance_km'],
                        'offered_at' => now(),
                        'expires_at' => now()->addSeconds($timeout),
                    ]);
                    $req->increment('attempts');
                    $candidate['doctor']->forceFill(['last_offered_at' => now()])->saveQuietly();
                    $events[] = ['offered', $offer];
                }

                return $events;
            }

            $live = DoctorMatchOffer::query()
                ->where('doctor_match_request_id', $req->id)
                ->where('status', DoctorMatchOffer::OFFERED)
                ->lockForUpdate()
                ->first();

            if ($live) {
                if ($live->expires_at && $live->expires_at->isFuture()) {
                    return $events;
                }
                $live->update(['status' => DoctorMatchOffer::EXPIRED, 'responded_at' => now()]);
            }

            if ($req->expires_at && $req->expires_at->isPast()) {
                $this->expireLocked($req, 'timed_out');
                $events[] = ['expired', $req];

                return $events;
            }

            if ($req->attempts >= max(1, DoctorNowSettings::int('doctor_now_max_attempts'))) {
                $this->expireLocked($req, 'no_doctor_accepted');
                $events[] = ['expired', $req];

                return $events;
            }

            $candidate = $this->pickCandidate($req);
            if (! $candidate) {
                return $events; // keep searching until a doctor comes online or time runs out
            }

            $timeout = max(10, DoctorNowSettings::int('doctor_now_offer_timeout_seconds'));
            $offer = DoctorMatchOffer::query()->create([
                'doctor_match_request_id' => $req->id,
                'appointment_id' => $req->appointment_id,
                'doctor_profile_id' => $candidate['doctor']->id,
                'status' => DoctorMatchOffer::OFFERED,
                'distance_km' => $candidate['distance_km'],
                'offered_at' => now(),
                'expires_at' => now()->addSeconds($timeout),
            ]);
            $req->increment('attempts');
            $candidate['doctor']->forceFill(['last_offered_at' => now()])->saveQuietly();
            $events[] = ['offered', $offer];

            return $events;
        });

        foreach ($events as [$type, $model]) {
            try {
                if ($type === 'offered') {
                    $this->notifyOffer($model);
                } elseif ($type === 'expired') {
                    $this->notifyExpired($model);
                }
            } catch (\Throwable $e) {
                Log::warning('[DoctorMatch] notify failed: '.$e->getMessage());
            }
        }
    }

    protected function expireLocked(DoctorMatchRequest $req, string $reason): void
    {
        $this->closeLiveOffers($req);
        $req->update(['status' => DoctorMatchRequest::EXPIRED, 'outcome_reason' => $reason]);
        $req->appointment?->update([
            'status' => Appointment::STATUS_CANCELLED,
            'cancel_reason' => $reason === 'timed_out'
                ? 'No doctor accepted in time — you were not charged'
                : 'No available doctor accepted the request — you were not charged',
        ]);
    }

    /** Refresh every offered doctor's inbox (taken / expired state). */
    public function bumpOffered(int $requestId): void
    {
        try {
            DoctorProfile::query()->whereIn('id', DoctorMatchOffer::query()->where('doctor_match_request_id', $requestId)->pluck('doctor_profile_id'))
                ->pluck('user_id')->each(fn ($id) => ProviderAlerts::bump((int) $id));
        } catch (\Throwable) {
        }
    }

    /** Admin manual assignment (Live requests): the doctor gets an "assigned" alert. */
    public function assignByAdmin(DoctorMatchRequest $request, DoctorProfile $doctor): DoctorMatchRequest
    {
        $offer = DB::transaction(function () use ($request, $doctor) {
            $req = DoctorMatchRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $req || $req->status !== DoctorMatchRequest::SEARCHING) {
                throw new DoctorMatchException('This request is no longer open.', 409);
            }

            return DoctorMatchOffer::query()->create([
                'doctor_match_request_id' => $req->id,
                'appointment_id' => $req->appointment_id,
                'doctor_profile_id' => $doctor->id,
                'status' => DoctorMatchOffer::OFFERED,
                'offered_at' => now(),
                'expires_at' => now()->addMinutes(5),
            ]);
        });
        $result = $this->accept($offer, $doctor);
        if ($doctor->user) {
            ProviderAlerts::assigned($doctor->user, 'Consult assigned to you', 'Prompt Aid operations assigned an on-demand '.$result->mode.' '.$result->specialty.' consult to you.', [
                'service' => 'doctor_now', 'request_id' => $result->id, 'url' => '/staff/live-consults',
            ]);
        }

        return $result;
    }

    protected function closeLiveOffers(DoctorMatchRequest $req): void
    {
        $this->bumpOffered($req->id);
        DoctorMatchOffer::query()
            ->where('doctor_match_request_id', $req->id)
            ->where('status', DoctorMatchOffer::OFFERED)
            ->update(['status' => DoctorMatchOffer::EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Best next doctor: right specialty, online with a fresh heartbeat, not
     * busy, not already offered this request, not holding another live
     * offer; in-person within the admin radius (nearest first, in 2 km
     * bands). Fairness: least recently offered/assigned first, then rating.
     *
     * @return array{doctor: DoctorProfile, distance_km: ?float, clinic: ?Clinic}|null
     */
    protected function pickCandidate(DoctorMatchRequest $req): ?array
    {
        return $this->pickCandidates($req, false)->first();
    }

    /**
     * Every qualifying doctor, best first. Broadcast mode allows a doctor to
     * hold several live offers (their alert queues them).
     *
     * @return Collection<int, array{doctor: DoctorProfile, distance_km: ?float, clinic: ?Clinic}>
     */
    public function pickCandidates(DoctorMatchRequest $req, bool $broadcast): Collection
    {
        $alreadyOffered = DoctorMatchOffer::query()->where('doctor_match_request_id', $req->id)->pluck('doctor_profile_id');
        $holdingOffer = $broadcast ? collect() : DoctorMatchOffer::query()
            ->where('status', DoctorMatchOffer::OFFERED)
            ->where('expires_at', '>', now())
            ->pluck('doctor_profile_id');
        $patientUserId = $req->patient?->user_id;

        $doctors = self::onlineQuery($req->specialty)
            ->whereNotIn('id', $alreadyOffered->merge($holdingOffer)->unique()->all() ?: [0])
            ->when($patientUserId, fn ($q) => $q->where('user_id', '!=', $patientUserId))
            ->with('clinics')
            ->limit(200)
            ->get();

        $radius = DoctorNowSettings::float('doctor_now_inperson_radius_km');
        if ($req->radius_km !== null) {
            // The patient chose a search radius: use it (capped by the admin maximum).
            $radius = min((float) $req->radius_km, DispatchSettings::maxRadius());
        }
        $rows = new Collection;

        foreach ($doctors as $doctor) {
            $distance = null;
            $clinic = null;
            if ($req->mode === Appointment::MODE_IN_PERSON) {
                $loc = $this->doctorRooms($doctor, (float) $req->lat, (float) $req->lng);
                $own = \App\Models\ProviderAvailability::query()->where('user_id', $doctor->user_id)->value('max_radius_km');
                if (! $loc || $loc['distance_km'] > $radius || ($own !== null && $loc['distance_km'] > (float) $own)) {
                    continue;
                }
                $distance = round($loc['distance_km'], 2);
                $clinic = $loc['clinic'];
            }
            $fair = max($doctor->last_offered_at?->getTimestamp() ?? 0, $doctor->last_assigned_at?->getTimestamp() ?? 0);
            $rows->push([
                'doctor' => $doctor,
                'distance_km' => $distance,
                'clinic' => $clinic,
                'sort' => [$distance === null ? 0 : (int) floor($distance / 2), $fair, -1 * (float) $doctor->rating_avg, $doctor->id],
            ]);
        }

        return $rows->sort(function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        })->values()->map(function ($r) {
            unset($r['sort']);

            return $r;
        });
    }

    /**
     * Where an in-person patient would go: if the doctor shared a fresh
     * location, the practice (clinic) nearest to the doctor right now;
     * otherwise their practice nearest the patient; if the doctor has no
     * geocoded practice, their fresh shared location itself.
     *
     * @return array{lat: float, lng: float, clinic: ?Clinic, distance_km: float}|null
     */
    public function doctorRooms(DoctorProfile $doctor, float $patientLat, float $patientLng): ?array
    {
        $clinics = $doctor->relationLoaded('clinics') ? $doctor->clinics : $doctor->clinics()->get();
        $clinics = $clinics->filter(fn (Clinic $c) => $c->lat !== null && $c->lng !== null);

        $locFresh = $doctor->current_lat !== null && $doctor->current_lng !== null && $doctor->location_updated_at
            && $doctor->location_updated_at->gte(now()->subMinutes(max(1, DoctorNowSettings::int('doctor_now_location_stale_minutes'))));

        $clinic = null;
        if ($clinics->isNotEmpty()) {
            $ref = $locFresh ? [(float) $doctor->current_lat, (float) $doctor->current_lng] : [$patientLat, $patientLng];
            $clinic = $clinics->sortBy(fn (Clinic $c) => $this->geo->distanceKm($ref[0], $ref[1], (float) $c->lat, (float) $c->lng))->first();
        }

        if ($clinic) {
            return [
                'lat' => (float) $clinic->lat,
                'lng' => (float) $clinic->lng,
                'clinic' => $clinic,
                'distance_km' => $this->geo->distanceKm($patientLat, $patientLng, (float) $clinic->lat, (float) $clinic->lng),
            ];
        }

        if ($locFresh) {
            return [
                'lat' => (float) $doctor->current_lat,
                'lng' => (float) $doctor->current_lng,
                'clinic' => null,
                'distance_km' => $this->geo->distanceKm($patientLat, $patientLng, (float) $doctor->current_lat, (float) $doctor->current_lng),
            ];
        }

        return null;
    }

    /** Doctor accepts their live offer → appointment confirmed with them, invoice raised. */
    public function accept(DoctorMatchOffer $offer, DoctorProfile $doctor): DoctorMatchRequest
    {
        if ($offer->doctor_profile_id !== $doctor->id) {
            throw new DoctorMatchException('This offer is not yours.', 404);
        }

        $result = DB::transaction(function () use ($offer, $doctor) {
            $offer = DoctorMatchOffer::query()->whereKey($offer->id)->lockForUpdate()->first();
            $req = DoctorMatchRequest::query()->whereKey($offer->doctor_match_request_id)->lockForUpdate()->first();

            if ($req && $req->status === DoctorMatchRequest::MATCHED && (int) $req->doctor_profile_id !== (int) $doctor->id) {
                return 'taken';
            }
            if ($offer->status !== DoctorMatchOffer::OFFERED || ! $req || $req->status !== DoctorMatchRequest::SEARCHING) {
                return 'gone';
            }
            // 3s grace for the round trip from a countdown that hit zero.
            if ($offer->expires_at && $offer->expires_at->lt(now()->subSeconds(3))) {
                $offer->update(['status' => DoctorMatchOffer::EXPIRED, 'responded_at' => now()]);

                return 'expired';
            }
            if ($this->activeConsultFor($doctor)) {
                return 'busy';
            }

            $clinic = null;
            if ($req->mode === Appointment::MODE_IN_PERSON) {
                $clinic = $this->doctorRooms($doctor, (float) $req->lat, (float) $req->lng)['clinic'] ?? null;
            }
            $billingClinicId = $clinic?->id ?? $doctor->clinics()->value('clinics.id');

            $now = now();
            $offer->update(['status' => DoctorMatchOffer::ACCEPTED, 'responded_at' => $now]);
            $req->appointment->update([
                'doctor_profile_id' => $doctor->id,
                'clinic_id' => $clinic?->id,
                'date' => $now->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->toDateString(),
                'start_time' => $now->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->format('H:i:s'),
                'end_time' => $now->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->addMinutes(self::DEFAULT_DURATION_MINUTES)->format('H:i:s'),
                'status' => Appointment::STATUS_CONFIRMED,
            ]);

            $modeLabel = match ($req->mode) {
                Appointment::MODE_VIDEO => 'video',
                Appointment::MODE_CALLBACK => 'call-back',
                default => 'in-person',
            };
            $breakdown = (array) $req->price_breakdown;
            $suffix = ! empty($breakdown['notes']) ? ' ('.implode('; ', (array) $breakdown['notes']).')' : '';
            $invoice = Invoice::query()->create([
                'appointment_id' => $req->appointment_id,
                'patient_profile_id' => $req->patient_profile_id,
                'clinic_id' => $billingClinicId,
                'subtotal' => $req->price,
                'tax' => 0,
                'discount' => 0,
                'total' => $req->price,
                'status' => Invoice::STATUS_UNPAID,
                'due_date' => $now->toDateString(),
            ]);
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'description' => mb_substr("On-demand {$modeLabel} consultation — {$req->specialty}{$suffix}", 0, 255),
                'qty' => 1,
                'unit_price' => $req->price,
                'amount' => $req->price,
            ]);

            $req->update([
                'status' => DoctorMatchRequest::MATCHED,
                'outcome_reason' => 'accepted',
                'matched_at' => $now,
                'doctor_profile_id' => $doctor->id,
                'clinic_id' => $clinic?->id,
                'invoice_id' => $invoice->id,
            ]);

            $doctor->update(['availability' => DoctorProfile::BUSY]);
            $doctor->forceFill(['last_assigned_at' => $now, 'last_seen_at' => $now])->saveQuietly();

            // Broadcast: everyone else's popup flips to "accepted by another doctor".
            DoctorMatchOffer::query()->where('doctor_match_request_id', $req->id)->where('id', '!=', $offer->id)
                ->where('status', DoctorMatchOffer::OFFERED)
                ->update(['status' => DoctorMatchOffer::EXPIRED, 'responded_at' => $now, 'updated_at' => $now]);

            return $req;
        });
        $this->bumpOffered((int) $offer->doctor_match_request_id);

        if ($result === 'taken') {
            throw new DoctorMatchException('This consult was taken by another doctor.', 409);
        }
        if ($result === 'gone') {
            throw new DoctorMatchException('This request has already been taken or cancelled.', 409);
        }
        if ($result === 'expired') {
            $this->progress($offer->matchRequest);

            throw new DoctorMatchException('This offer has expired.', 409);
        }
        if ($result === 'busy') {
            throw new DoctorMatchException('Finish your current consult before accepting another.', 409);
        }

        try {
            $this->notifyMatched($result);
        } catch (\Throwable $e) {
            Log::warning('[DoctorMatch] notify matched failed: '.$e->getMessage());
        }

        // Video: the patient's screen rings straight away ("Dr X is ready — joining…").
        if ($result->mode === Appointment::MODE_VIDEO && $result->appointment) {
            try {
                app(ConsultCallService::class)->ring($result->appointment->fresh());
            } catch (\Throwable $e) {
                Log::warning('[DoctorMatch] ring failed: '.$e->getMessage());
            }
        }

        return $result->fresh();
    }

    public function decline(DoctorMatchOffer $offer, DoctorProfile $doctor): void
    {
        if ($offer->doctor_profile_id !== $doctor->id) {
            throw new DoctorMatchException('This offer is not yours.', 404);
        }

        $updated = DoctorMatchOffer::query()->whereKey($offer->id)->where('status', DoctorMatchOffer::OFFERED)
            ->update(['status' => DoctorMatchOffer::DECLINED, 'responded_at' => now(), 'updated_at' => now()]);

        if (! $updated) {
            throw new DoctorMatchException('This offer is no longer open.', 409);
        }

        $this->progress($offer->matchRequest);
    }

    /** Doctor's live offer (after lazily progressing anything that lapsed). */
    public function currentOfferFor(DoctorProfile $doctor): ?DoctorMatchOffer
    {
        $this->tick();

        return DoctorMatchOffer::query()
            ->where('doctor_profile_id', $doctor->id)
            ->where('status', DoctorMatchOffer::OFFERED)
            ->where('expires_at', '>', now()->subSeconds(2))
            ->with(['matchRequest.patient.user'])
            ->latest('id')
            ->first();
    }

    /** The on-demand consult a doctor is currently on (matched, not finished). */
    public function activeConsultFor(DoctorProfile $doctor): ?DoctorMatchRequest
    {
        return DoctorMatchRequest::query()
            ->where('doctor_profile_id', $doctor->id)
            ->where('status', DoctorMatchRequest::MATCHED)
            ->whereHas('appointment', fn ($a) => $a
                ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN])
                ->whereNull('video_ended_at'))
            ->where('matched_at', '>=', now()->subHours(6))
            ->with(['appointment.patient.user', 'clinic'])
            ->latest('id')
            ->first();
    }

    /**
     * Free a busy doctor once their on-demand consult is over (completed /
     * cancelled / no-show / video ended). Only flips busy → available, and
     * only when no other on-demand consult is still running.
     */
    public function release(?DoctorProfile $doctor): void
    {
        if (! $doctor) {
            return;
        }
        $doctor->refresh();
        if ($doctor->availability !== DoctorProfile::BUSY || $this->activeConsultFor($doctor)) {
            return;
        }

        $doctor->update(['availability' => DoctorProfile::AVAILABLE]);

        if ($this->isFresh($doctor)) {
            $this->notifyWaitlist($doctor);
        }
    }

    public function releaseForAppointment(Appointment $appointment): void
    {
        if ($appointment->doctor_profile_id) {
            $this->release(DoctorProfile::query()->find($appointment->doctor_profile_id));
        }
    }

    /** Patient pays the consult invoice (mock gateway today, same contract as /api/invoices/{id}/pay). */
    public function pay(DoctorMatchRequest $request, string $method = 'card'): array
    {
        $invoice = $request->invoice;
        if (! $invoice || $request->status !== DoctorMatchRequest::MATCHED) {
            throw new DoctorMatchException('There is nothing to pay for this request yet.', 409);
        }
        if ($invoice->status === Invoice::STATUS_PAID) {
            throw new DoctorMatchException('This consult is already paid.', 409);
        }

        $result = $this->gateway->charge($invoice, $method);
        if (! empty($result['pending'])) {
            // Online gateways: the patient pays in the payment sheet; the webhook settles the invoice.
            return ['success' => false, 'pending' => true, 'reference' => null, 'message' => 'Please pay online using the payment sheet.'];
        }
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'method' => $method,
            'amount' => $invoice->total,
            'status' => $result['success'] ? 'success' : 'failed',
            'gateway' => 'mock',
            'gateway_ref' => $result['reference'],
        ]);
        if ($result['success']) {
            $invoice->update(['status' => Invoice::STATUS_PAID]);
        }

        return $result;
    }

    /**
     * Sweep every open request (lapsed offers, timeouts, newly online
     * doctors) and expire old waitlist rows. Throttled to once per 3s.
     *
     * @return int requests examined
     */
    public function tick(bool $force = false): int
    {
        if (! $force && ! Cache::add('doctor_now_tick_throttle', 1, 3)) {
            return 0;
        }

        $lock = Cache::lock('doctor_now_tick', 20);
        if (! $lock->get()) {
            return 0;
        }

        try {
            $open = DoctorMatchRequest::query()
                ->where('status', DoctorMatchRequest::SEARCHING)
                ->orderBy('id')
                ->limit(100)
                ->get();
            foreach ($open as $req) {
                $this->progress($req);
            }

            DoctorMatchWaitlist::query()
                ->where('status', DoctorMatchWaitlist::WAITING)
                ->where('expires_at', '<=', now())
                ->update(['status' => DoctorMatchWaitlist::EXPIRED, 'updated_at' => now()]);

            return $open->count();
        } finally {
            $lock->release();
        }
    }

    // ------------------------------------------------------------------
    // Payloads (shared by the web page, the Filament console and the API)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function patientPayload(DoctorMatchRequest $request): array
    {
        $request->loadMissing(['appointment', 'doctor.user', 'clinic', 'invoice']);
        $appt = $request->appointment;
        $doctor = $request->doctor;
        $online = self::onlineQuery($request->specialty)->count();

        $doctorData = null;
        if ($doctor && $request->status !== DoctorMatchRequest::SEARCHING) {
            $doctorData = [
                'id' => $doctor->id,
                'name' => $doctor->user ? 'Dr. '.$doctor->user->name : 'Your doctor',
                'specialization' => $doctor->specialization,
                'qualification' => $doctor->qualification,
                'rating' => round((float) $doctor->rating_avg, 1),
                'rating_count' => (int) $doctor->rating_count,
                'photo_url' => \App\Support\Widgets\WidgetDataService::mediaUrl($doctor->user?->avatar),
                'profile_url' => \Illuminate\Support\Facades\Route::has('doctors.show') ? route('doctors.show', $doctor) : null,
            ];
        }

        $clinic = $request->clinic;
        $clinicData = $clinic ? [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'address' => trim(implode(', ', array_filter([$clinic->address, $clinic->city]))),
            'lat' => $clinic->lat !== null ? (float) $clinic->lat : null,
            'lng' => $clinic->lng !== null ? (float) $clinic->lng : null,
            'directions_url' => $clinic->lat !== null && $clinic->lng !== null
                ? 'https://www.google.com/maps/dir/?api=1&destination='.$clinic->lat.','.$clinic->lng
                : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($clinic->shuttleAddress()),
            'shuttle_url' => $clinic->shuttleUrl(),
        ] : null;

        // In-person with no geocoded practice: directions to the doctor's shared location.
        if (! $clinicData && $doctor && $request->status === DoctorMatchRequest::MATCHED && $request->mode === Appointment::MODE_IN_PERSON
            && $doctor->current_lat !== null && $doctor->current_lng !== null) {
            $clinicData = [
                'id' => null,
                'name' => 'Doctor\'s practice location',
                'address' => null,
                'lat' => (float) $doctor->current_lat,
                'lng' => (float) $doctor->current_lng,
                'directions_url' => 'https://www.google.com/maps/dir/?api=1&destination='.$doctor->current_lat.','.$doctor->current_lng,
                'shuttle_url' => \Illuminate\Support\Facades\Route::has('shuttle')
                    ? route('shuttle', ['to' => 'Doctor\'s practice', 'to_lat' => (string) $doctor->current_lat, 'to_lng' => (string) $doctor->current_lng]).'#request'
                    : null,
            ];
        }

        $invoice = $request->invoice;
        $calls = app(ConsultCallService::class);
        try {
            $dispatch = \App\Models\EmergencyContact::primary();
        } catch (\Throwable) {
            $dispatch = null;
        }
        $apptActive =$appt && in_array($appt->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true);

        return [
            'id' => $request->id,
            'appointment_id' => $request->appointment_id,
            'booking_ref' => $appt?->booking_ref,
            'status' => $request->status,
            'appointment_status' => $appt?->status,
            'outcome_reason' => $request->outcome_reason,
            'outcome_message' => $this->outcomeMessage($request),
            'specialty' => $request->specialty,
            'mode' => $request->mode,
            'price' => (float) $request->price,
            'currency' => 'ZAR',
            'price_breakdown' => $request->price_breakdown,
            'attempts' => (int) $request->attempts,
            'max_attempts' => max(1, DoctorNowSettings::int('doctor_now_max_attempts')),
            'created_at' => $request->created_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'seconds_left' => $request->status === DoctorMatchRequest::SEARCHING && $request->expires_at
                ? max(0, (int) ceil(abs(now()->diffInSeconds($request->expires_at))) * ($request->expires_at->isFuture() ? 1 : 0))
                : 0,
            'offer_pending' => $request->status === DoctorMatchRequest::SEARCHING && DoctorMatchOffer::query()
                ->where('doctor_match_request_id', $request->id)->where('status', DoctorMatchOffer::OFFERED)->exists(),
            'online_doctors' => $online,
            // "Sent to N available doctors — the first to accept takes your consult."
            'alerted_doctors' => $request->status === DoctorMatchRequest::SEARCHING ? DoctorMatchOffer::query()
                ->where('doctor_match_request_id', $request->id)->where('status', DoctorMatchOffer::OFFERED)->where('expires_at', '>', now())->count() : 0,
            'broadcast' => DispatchSettings::mode('doctor_now') === 'broadcast',
            'eta_seconds' => $request->status === DoctorMatchRequest::SEARCHING && $online > 0 ? $this->typicalSecondsToAccept($request->specialty, $request->mode) : null,
            'matched_at' => $request->matched_at?->toIso8601String(),
            'doctor' => $doctorData,
            'clinic' => $clinicData,
            'can_join_video' => (bool) ($appt && $request->status === DoctorMatchRequest::MATCHED && $appt->canJoinVideoNow()),
            'video_url' => $appt && $request->status === DoctorMatchRequest::MATCHED && $appt->isVideo() && \Illuminate\Support\Facades\Route::has('account.appointments.video')
                ? route('account.appointments.video', $appt) : null,
            // In-page / in-app call (no external browser) + the "incoming call" state once the doctor is ready.
            'call_url' => $appt && $request->status === DoctorMatchRequest::MATCHED && $appt->isVideo() && \Illuminate\Support\Facades\Route::has('account.appointments.call')
                ? route('account.appointments.call', $appt) : null,
            'ring' => $appt && $request->status === DoctorMatchRequest::MATCHED ? $calls->ringPayload($appt) : null,
            'auto_join_seconds' => ConsultCallService::autoJoinSeconds(),
            'callback' => $appt ? $calls->callbackPayload($appt) : null,
            'triage' => $request->triage_submission_id ? (fn ($t) => $t ? ['reference' => $t['reference'], 'level' => $t['level'], 'level_label' => $t['level_label']] : null)($calls->triageSummary($request->triageSubmission)) : null,
            'dispatch_phone' => $dispatch?->phone,
            'dispatch_tel' => $dispatch?->tel_url,
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'total' => (float) $invoice->total,
                'status' => $invoice->status,
            ] : null,
            'can_cancel' => $request->status === DoctorMatchRequest::SEARCHING || ($request->status === DoctorMatchRequest::MATCHED && $apptActive),
            'cancel_fee_now' => $request->status === DoctorMatchRequest::MATCHED ? $this->pricing->cancellationFee($request) : 0.0,
            'cancel_fee' => (float) $request->cancel_fee,
            'waitlisted' => in_array($request->status, [DoctorMatchRequest::SEARCHING, DoctorMatchRequest::EXPIRED], true) && $this->isWaitlisted($request),
            'book_url' => \Illuminate\Support\Facades\Route::has('doctors.index') ? route('doctors.index', ['specialization' => $request->specialty]) : null,
        ];
    }

    public function outcomeMessage(DoctorMatchRequest $request): ?string
    {
        return match ($request->outcome_reason) {
            'timed_out' => 'No doctor accepted in time. You have not been charged.',
            'no_doctor_accepted' => 'The available doctors couldn\'t take your request. You have not been charged.',
            'cancelled_by_patient' => 'You cancelled this request. You have not been charged.',
            'cancelled_by_patient_after_accept' => (float) $request->cancel_fee > 0
                ? 'You cancelled after the doctor accepted. A cancellation fee of R'.number_format((float) $request->cancel_fee, 2).' applies.'
                : 'You cancelled within the free cancellation window. You have not been charged.',
            'cancelled_by_provider' => 'The practice cancelled this consult. You have not been charged.',
            'accepted' => $request->mode === Appointment::MODE_CALLBACK && $request->doctor?->user
                ? 'Dr. '.$request->doctor->user->name.' will call you'.(($p = ConsultCallService::displayPhone(app(ConsultCallService::class)->callbackDigits($request->appointment))) ? ' on '.$p : '').' within '.ConsultCallService::targetMinutes().' minutes.'
                : null,
            default => null,
        };
    }

    /** Median request→accept seconds over the last 14 days (null until there are 3+ samples). */
    public function typicalSecondsToAccept(string $specialty, string $mode): ?int
    {
        return Cache::remember('doctor_now_eta.'.md5($specialty.'|'.$mode), 300, function () use ($specialty, $mode) {
            $samples = DoctorMatchRequest::query()
                ->where('specialty', $specialty)
                ->where('mode', $mode)
                ->whereNotNull('matched_at')
                ->where('created_at', '>=', now()->subDays(14))
                ->limit(500)
                ->get(['created_at', 'matched_at'])
                ->map(fn ($r) => max(0, $r->matched_at->getTimestamp() - $r->created_at->getTimestamp()))
                ->sort()
                ->values();

            if ($samples->count() < 3) {
                return null;
            }

            return (int) $samples[intdiv($samples->count(), 2)];
        });
    }

    /** @return array<string, mixed>|null */
    public function doctorOfferPayload(?DoctorMatchOffer $offer): ?array
    {
        if (! $offer) {
            return null;
        }
        $req = $offer->matchRequest;
        $patientName = (string) ($req?->patient?->user?->name ?? 'Patient');

        return [
            'id' => $offer->id,
            'request_id' => $offer->doctor_match_request_id,
            'appointment_id' => $offer->appointment_id,
            'mode' => $req?->mode,
            'mode_label' => self::modeLabel($req?->mode),
            'specialty' => $req?->specialty,
            'patient_first_name' => trim(strtok($patientName, ' ') ?: $patientName),
            'lines' => $req ? $this->offerLines($req) : [],
            'triage_level' => $req?->triageSubmission?->level,
            'distance_km' => $offer->distance_km,
            'price' => (float) ($req?->price ?? 0),
            'offered_at' => $offer->offered_at?->toIso8601String(),
            'expires_at' => $offer->expires_at?->toIso8601String(),
            'seconds_left' => $offer->secondsLeft(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function doctorConsultPayload(?DoctorMatchRequest $req): ?array
    {
        if (! $req) {
            return null;
        }
        $appt = $req->appointment;

        return [
            'request_id' => $req->id,
            'appointment_id' => $req->appointment_id,
            'booking_ref' => $appt?->booking_ref,
            'mode' => $req->mode,
            'specialty' => $req->specialty,
            'patient_name' => $appt?->patient?->user?->name,
            'clinic' => $req->clinic ? ['id' => $req->clinic->id, 'name' => $req->clinic->name] : null,
            'matched_at' => $req->matched_at?->toIso8601String(),
            'can_join_video' => (bool) $appt?->canJoinVideoNow(),
            'price' => (float) $req->price,
            'mode_label' => self::modeLabel($req->mode),
            'callback' => $appt ? app(ConsultCallService::class)->callbackPayload($appt, true) : null,
            'card_url' => $appt && $req->mode === Appointment::MODE_CALLBACK && class_exists(\App\Filament\Pages\CallbackConsult::class)
                ? \App\Filament\Pages\CallbackConsult::getUrl(['appointment' => $appt->id], panel: 'admin') : null,
        ];
    }

    public static function modeLabel(?string $mode): string
    {
        return match ($mode) {
            Appointment::MODE_VIDEO => 'Video consult',
            Appointment::MODE_CALLBACK => 'Call back',
            default => 'In-person consult',
        };
    }

    /**
     * Alert lines for the doctor: age/sex, triage level + presenting
     * symptoms, call-back preference.
     *
     * @return array<int, string>
     */
    public function offerLines(DoctorMatchRequest $req): array
    {
        $req->loadMissing(['patient.user', 'appointment', 'triageSubmission']);

        return app(ConsultCallService::class)->alertLines($req->patient, $req->triageSubmission, $req->appointment, (string) $req->mode);
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    protected function notifyOffer(DoctorMatchOffer $offer): void
    {
        $offer->loadMissing(['doctor.user', 'matchRequest']);
        $user = $offer->doctor?->user;
        if (! $user) {
            return;
        }
        $req = $offer->matchRequest;
        $req->loadMissing('triageSubmission');
        $secs = max(10, DoctorNowSettings::int('doctor_now_offer_timeout_seconds'));
        $level = $req->triageSubmission?->level;
        $prefix = $level ? strtoupper($level).' · ' : '';
        $title = $req->mode === Appointment::MODE_CALLBACK
            ? $prefix.'Patient asks a doctor to call back NOW'
            : ($req->mode === Appointment::MODE_VIDEO ? $prefix.'Patient needs a video consult NOW' : 'See a patient now - '.$req->specialty);
        $lines = $this->offerLines($req);
        $body = trim(implode(' · ', array_slice($lines, 0, 3)).' Accept within '.$secs.'s — first doctor to accept takes it.');

        ProviderAlerts::offer($user, $title, $body, [
            'service' => 'doctor_now', 'request_id' => $req->id, 'offer_id' => $offer->id, 'offer_key' => 'dm:'.$offer->id,
            'route' => 'doctor_offer', 'params' => ['offer_id' => $offer->id], 'url' => '/staff/live-consults',
        ]);
    }

    protected function notifyMatched(DoctorMatchRequest $req): void
    {
        $req->loadMissing(['doctor.user', 'patient.user']);
        $doctorName = $req->doctor?->user?->name ?? 'your doctor';
        $patientUser = $req->patient?->user;
        $doctorUser = $req->doctor?->user;

        // Video: ConsultCallService::ring() sends the "Dr X is calling you" push right after this.
        if ($patientUser && $req->mode !== Appointment::MODE_VIDEO) {
            $how = $req->mode === Appointment::MODE_CALLBACK
                ? ($this->outcomeMessage($req) ?? 'The doctor will call you shortly.').' Keep your phone close.'
                : 'Head to the practice — directions are on your request page.';
            $this->notifier->push($patientUser, $req->mode === Appointment::MODE_CALLBACK ? 'A doctor will call you back' : 'Doctor matched', "Dr. {$doctorName} accepted your request. {$how}", [
                'type' => 'appointment',
                'data' => ['route' => 'see_doctor_now', 'params' => ['appointment_id' => $req->appointment_id, 'request_id' => $req->id], 'url' => '/account/see-a-doctor'],
            ]);
        }

        if ($doctorUser) {
            $cardUrl = match ($req->mode) {
                Appointment::MODE_CALLBACK => \App\Filament\Pages\CallbackConsult::getUrl(['appointment' => $req->appointment_id], panel: 'admin'),
                Appointment::MODE_VIDEO => \App\Filament\Pages\ConsultCall::getUrl(['appointment' => $req->appointment_id], panel: 'admin'),
                default => url('/staff/live-consults'),
            };
            StaffNotifier::alert(
                $doctorUser,
                'On-demand consult confirmed',
                "{$patientUser?->name} — ".mb_strtolower(self::modeLabel($req->mode)).'. Open it to start.',
                icon: 'heroicon-o-bolt',
                color: 'success',
                url: $cardUrl,
                actionLabel: 'Open',
            );
        }
    }

    protected function notifyExpired(DoctorMatchRequest $req): void
    {
        $user = $req->patient?->user;
        if (! $user) {
            return;
        }

        try {
            $dispatch = \App\Models\EmergencyContact::primary()?->phone;
        } catch (\Throwable) {
            $dispatch = null;
        }
        $this->notifier->push($user, 'No doctor available', ($this->outcomeMessage($req) ?? 'Your request expired.').' Try again'.($dispatch ? ', or call dispatch on '.$dispatch.' if it gets worse.' : '.'), [
            'type' => 'appointment',
            'data' => ['route' => 'see_doctor_now', 'params' => ['request_id' => $req->id], 'url' => '/account/see-a-doctor'],
        ]);
    }
}
