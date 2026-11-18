<?php

namespace App\Services\PracticeCalls;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\DoctorMatchRequest;
use App\Models\DoctorProfile;
use App\Models\Ride;
use App\Models\User;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Notifications\InAppNotifier;
use App\Services\Payments\RidePaymentService;
use App\Services\Rides\PricingService;
use App\Services\Rides\RideDispatchService;
use App\Services\Rides\RideSeriesService;
use App\Support\PracticeCallSettings;
use App\Support\StaffNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * What the practice captures DURING the practice call:
 *
 *  - the appointment (GP of the practice, date + free slot from the GP's
 *    weekly sessions minus bookings and time off, in-person or video,
 *    reason / notes). It updates the request's own appointment to
 *    "confirmed", so it is on the doctor's calendar (Filament + app) and the
 *    patient's (web, .ics, app) at once; both are notified.
 *  - an optional shuttle: patient location (live GPS shared from the call,
 *    else saved address) → practice address, fares from
 *    PricingService::quotesFor, offered to the patient on their call screen.
 *    The patient chooses a vehicle and pays with the normal ride payment
 *    sheet; pickup = appointment start − route ETA − buffer; optional return.
 */
class PracticeBookingService
{
    public function __construct(
        protected AppointmentBookingService $booking,
        protected PricingService $pricing,
    ) {}

    protected function requirePractice(CallLog $call, User $user): void
    {
        if (app(PracticeCallService::class)->authorizeParty($call, $user) !== 'practice') {
            throw new DoctorMatchException('Only the practice can do this.', 403);
        }
    }

    // ------------------------------------------------------------------
    // Booking
    // ------------------------------------------------------------------

    /** GPs the practice can book (its doctors, the call's GP first). @return array<string, mixed> */
    public function options(CallLog $call): array
    {
        $meta = (array) $call->meta;
        $doctors = $this->doctorsFor($call);

        return [
            'modes' => array_values(array_intersect(['in_person', 'video'], (array) ($meta['booking_modes'] ?? ['in_person', 'video']))) ?: ['in_person'],
            'default_mode' => (string) ($meta['default_mode'] ?? 'in_person'),
            'shuttle' => (bool) ($meta['shuttle'] ?? true),
            'doctors' => $doctors->map(fn (DoctorProfile $d) => [
                'id' => $d->id,
                'name' => 'Dr. '.($d->user?->name ?? ''),
                'specialization' => $d->specialization,
                'availability' => $d->availability,
            ])->values()->all(),
            'default_doctor_id' => $call->doctor_profile_id ?: $doctors->first()?->id,
            'today' => now(Appointment::CLINIC_TIMEZONE)->toDateString(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, DoctorProfile> */
    public function doctorsFor(CallLog $call): \Illuminate\Support\Collection
    {
        $q = DoctorProfile::query()->where('status', 'active')->with('user');
        if ($call->clinic_id) {
            $q->whereHas('clinics', fn ($c) => $c->where('clinics.id', $call->clinic_id));
        } else {
            $q->whereKey($call->doctor_profile_id ?: 0);
        }

        return $q->get()->sortBy(fn (DoctorProfile $d) => [(int) $d->id === (int) $call->doctor_profile_id ? 0 : 1, $d->availability === DoctorProfile::OFFLINE ? 1 : 0, $d->id])->values();
    }

    /** Free slots for a GP on a date (clinic sessions, bookings and time off respected). @return array<string, mixed> */
    public function slots(CallLog $call, User $user, int $doctorId, string $date): array
    {
        $this->requirePractice($call, $user);
        $doctor = $this->doctorsFor($call)->firstWhere('id', $doctorId);
        if (! $doctor) {
            throw new DoctorMatchException('Choose one of your practice\'s doctors.', 422);
        }
        $day = $this->parseDate($date);
        $clinicId = (int) ($call->clinic_id ?: $doctor->clinics()->value('clinics.id'));
        $schedule = $clinicId ? $this->booking->slotSchedule($doctor, $clinicId, $day, $call->appointment_id) : ['date' => $day, 'time_off' => null, 'sessions' => []];

        return [
            'doctor_id' => $doctor->id,
            'date' => $day,
            'time_off' => $schedule['time_off'],
            'sessions' => $schedule['sessions'],
            'free' => collect($schedule['sessions'])->flatMap(fn ($s) => $s['slots'])->where('available', true)->pluck('time')->unique()->values()->all(),
        ];
    }

    /**
     * Create / update the booking on the call.
     *
     * @param  array{doctor_profile_id: int, date: string, start_time: string, mode: string, reason?: ?string, notes?: ?string, duration_minutes?: ?int, outside_sessions?: bool}  $data
     */
    public function capture(CallLog $call, User $user, array $data): Appointment
    {
        $this->requirePractice($call, $user);
        $appt = $call->appointment;
        $req = $call->matchRequest;
        if (! $appt || ($req && $req->status !== DoctorMatchRequest::MATCHED)) {
            throw new DoctorMatchException('This patient is no longer with your practice.', 409);
        }
        if (in_array($appt->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_COMPLETED, Appointment::STATUS_NO_SHOW], true)) {
            throw new DoctorMatchException('This booking was closed and can no longer be changed here.', 409);
        }
        $options = $this->options($call);
        $mode = (string) ($data['mode'] ?? '');
        if (! in_array($mode, $options['modes'], true)) {
            throw new DoctorMatchException('Choose in-person or video consult.', 422);
        }
        $doctor = $this->doctorsFor($call)->firstWhere('id', (int) ($data['doctor_profile_id'] ?? 0));
        if (! $doctor) {
            throw new DoctorMatchException('Choose one of your practice\'s doctors.', 422);
        }
        $date = $this->parseDate((string) ($data['date'] ?? ''));
        $start = substr((string) ($data['start_time'] ?? ''), 0, 5);
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start)) {
            throw new DoctorMatchException('Choose a time.', 422);
        }
        $clinicId = (int) ($call->clinic_id ?: $doctor->clinics()->value('clinics.id'));
        $schedule = $clinicId ? $this->booking->slotSchedule($doctor, $clinicId, $date, $appt->id) : ['time_off' => null, 'sessions' => []];
        $slot = null;
        $session = null;
        foreach ($schedule['sessions'] as $s) {
            foreach ($s['slots'] as $sl) {
                if ($sl['time'] === $start) {
                    $slot = $sl;
                    $session = $s;
                }
            }
        }
        $duration = max(5, min(240, (int) ($data['duration_minutes'] ?? ($session['slot_minutes'] ?? 30))));
        $startAt = Carbon::parse($date.' '.$start, Appointment::CLINIC_TIMEZONE);
        if ($startAt->lt(now()->subMinutes(5))) {
            throw new DoctorMatchException('That time has already passed.', 422);
        }
        if ($slot) {
            if (! $slot['available']) {
                throw new DoctorMatchException($slot['booked'] ? 'That slot is already booked — choose another.' : ($slot['blocked'] ? 'The doctor is off at that time.' : 'That slot is not available.'), 422);
            }
        } else {
            // Outside the GP's weekly sessions: allowed only when the practice says so,
            // and never over time off or another booking.
            if (empty($data['outside_sessions'])) {
                throw new DoctorMatchException('That time is outside Dr. '.($doctor->user?->name ?? '').'\'s sessions. Pick a free slot or tick "Book outside the doctor\'s sessions".', 422);
            }
            if ($schedule['time_off'] ?? null) {
                throw new DoctorMatchException('The doctor is off that day ('.$schedule['time_off'].').', 422);
            }
            $this->assertFree($doctor, $date, $start, $duration, $appt->id);
        }
        $end = $startAt->copy()->addMinutes($duration)->format('H:i');
        $reason = trim(strip_tags((string) ($data['reason'] ?? '')));
        $notes = trim(strip_tags((string) ($data['notes'] ?? '')));
        $wasBooked = $appt->status === Appointment::STATUS_CONFIRMED;

        DB::transaction(function () use ($appt, $doctor, $clinicId, $date, $start, $end, $mode, $reason, $req, $call, $user, $notes) {
            $appt->update([
                'doctor_profile_id' => $doctor->id,
                'clinic_id' => $clinicId ?: null,
                'date' => $date,
                'start_time' => $start.':00',
                'end_time' => $end.':00',
                'mode' => $mode === 'video' ? Appointment::MODE_VIDEO : Appointment::MODE_IN_PERSON,
                'visit_type' => $mode === 'video' ? 'telemed' : 'clinic',
                'status' => Appointment::STATUS_CONFIRMED,
                'reason' => mb_substr($reason !== '' ? $reason : 'Urgent (orange) — booked on the practice call', 0, 500),
            ]);
            $req?->update(['doctor_profile_id' => $doctor->id, 'mode' => $mode === 'video' ? Appointment::MODE_VIDEO : Appointment::MODE_IN_PERSON]);
            $meta = (array) $call->meta;
            $meta['booking'] = [
                'appointment_id' => $appt->id,
                'mode' => $mode,
                'doctor_profile_id' => $doctor->id,
                'date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null,
                'by_user_id' => $user->id,
                'at' => now()->toIso8601String(),
            ];
            CallLog::query()->whereKey($call->id)->update(['meta' => json_encode($meta), 'doctor_profile_id' => $doctor->id, 'updated_at' => now()]);
        });
        $appt->refresh();
        // A shuttle chosen earlier follows the booking time.
        $this->retimeShuttle($call->fresh(), $appt);
        $this->notifyBooked($appt, $doctor, $wasBooked);
        \Illuminate\Support\Facades\Cache::put('pc-version:'.$call->id, (string) microtime(true), 3600);

        return $appt;
    }

    protected function assertFree(DoctorProfile $doctor, string $date, string $start, int $duration, int $ignoreId): void
    {
        $toMin = fn (?string $t) => $t ? ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2) : 0;
        $s = $toMin($start);
        $e = $s + $duration;
        $clash = Appointment::query()->where('doctor_profile_id', $doctor->id)->whereDate('date', $date)->where('id', '!=', $ignoreId)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_MATCHING])
            ->get(['start_time', 'end_time'])
            ->first(fn ($a) => $s < ($a->end_time ? $toMin($a->end_time) : $toMin($a->start_time) + 1) && $toMin($a->start_time) < $e);
        if ($clash) {
            throw new DoctorMatchException('The doctor already has a booking at that time.', 422);
        }
        $blocks = $this->booking->timeOffOn($doctor, Carbon::parse($date));
        foreach ($blocks as $b) {
            if (! $b->start_time || ! $b->end_time || ($s < $toMin($b->end_time) && $toMin($b->start_time) < $e)) {
                throw new DoctorMatchException('The doctor is off at that time.', 422);
            }
        }
    }

    protected function parseDate(string $date): string
    {
        try {
            $d = Carbon::createFromFormat('Y-m-d', $date, Appointment::CLINIC_TIMEZONE);
        } catch (\Throwable) {
            throw new DoctorMatchException('Choose a date.', 422);
        }
        if (! $d || $d->toDateString() < now(Appointment::CLINIC_TIMEZONE)->toDateString() || $d->gt(now()->addDays(180))) {
            throw new DoctorMatchException('Choose a date from today up to 6 months ahead.', 422);
        }

        return $d->toDateString();
    }

    protected function notifyBooked(Appointment $appt, DoctorProfile $doctor, bool $update): void
    {
        $appt->loadMissing(['patient.user', 'clinic']);
        $when = Carbon::parse($appt->date->toDateString().' '.substr((string) $appt->start_time, 0, 5), Appointment::CLINIC_TIMEZONE)->format('D j M, H:i');
        $what = $appt->isVideo() ? 'video consult' : 'visit at '.($appt->clinic?->name ?? 'the practice');
        $patientUser = $appt->patient?->user;
        $notifier = app(InAppNotifier::class);
        if ($patientUser) {
            $notifier->send($patientUser, 'appointment', $update ? 'Booking updated' : 'Appointment booked', "Your {$what} with Dr. ".($doctor->user?->name ?? '')." is on {$when}. It is in your calendar under Appointments.", [
                'route' => 'appointment', 'params' => ['appointment_id' => $appt->id], 'url' => '/account/appointments',
            ]);
        }
        if ($doctor->user) {
            $line = ($patientUser?->name ?? 'Patient')." — {$what} on {$when} (booked by your practice on an urgent call).";
            $notifier->send($doctor->user, 'appointment', $update ? 'Booking updated' : 'New booking from your practice', $line, [
                'route' => 'doctor_appointment', 'params' => ['appointment_id' => $appt->id, 'date' => $appt->date->toDateString()],
            ]);
            try {
                StaffNotifier::alert($doctor->user, $update ? 'Booking updated' : 'New booking from your practice', $line, icon: 'heroicon-o-calendar-days', color: 'success',
                    url: Route::has('filament.admin.resources.appointments.index') ? route('filament.admin.resources.appointments.index') : null, actionLabel: 'Open');
            } catch (\Throwable) {
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function bookingPayload(CallLog $call, string $role): ?array
    {
        $appt = $call->appointment;
        if (! $appt || ! in_array($appt->status, [Appointment::STATUS_CONFIRMED, Appointment::STATUS_PENDING, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_COMPLETED], true)) {
            return null;
        }

        return $this->appointmentCard($appt) + ['notes' => $role === 'practice' ? (((array) $call->meta)['booking']['notes'] ?? null) : null];
    }

    /** @return array<string, mixed> */
    public function appointmentCard(Appointment $appt): array
    {
        $appt->loadMissing(['doctor.user', 'clinic']);
        $start = Carbon::parse($appt->date->toDateString().' '.substr((string) $appt->start_time, 0, 5), Appointment::CLINIC_TIMEZONE);

        return [
            'appointment_id' => $appt->id,
            'booking_ref' => $appt->booking_ref,
            'status' => $appt->status,
            'mode' => $appt->isVideo() ? 'video' : 'in_person',
            'mode_label' => $appt->isVideo() ? 'Video consult' : 'In-person consult',
            'date' => $appt->date->toDateString(),
            'start_time' => substr((string) $appt->start_time, 0, 5),
            'end_time' => substr((string) $appt->end_time, 0, 5),
            'starts_at' => $start->toIso8601String(),
            'when_label' => $start->format('D j M Y, H:i'),
            'doctor_id' => $appt->doctor_profile_id,
            'doctor_name' => $appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : null,
            'practice_name' => $appt->clinic?->name,
            'practice_address' => $appt->clinic ? trim(implode(', ', array_filter([$appt->clinic->address, $appt->clinic->city]))) : null,
            'reason' => $appt->reason,
            'ics_url' => Route::has('account.health.calendar') ? route('account.health.calendar', $appt) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Shuttle
    // ------------------------------------------------------------------

    /**
     * Practice: "Book a shuttle for the patient" → fares for every vehicle, offered
     * to the patient on their call screen.
     *
     * @param  array{pickup_lat?: ?float, pickup_lng?: ?float, pickup_address?: ?string, return_leg?: bool}  $data
     * @return array<string, mixed>
     */
    public function quoteShuttle(CallLog $call, User $user, array $data): array
    {
        $this->requirePractice($call, $user);
        $call->loadMissing(['clinic', 'patient', 'appointment']);
        $clinic = $call->appointment?->clinic ?? $call->clinic;
        if (! $clinic || $clinic->lat === null || $clinic->lng === null) {
            throw new DoctorMatchException('Your practice has no map location yet (Staff → Clinics → address / map pin), so a shuttle cannot be priced.', 422);
        }
        $meta = (array) $call->meta;
        $pickup = null;
        if (isset($data['pickup_lat'], $data['pickup_lng']) && is_numeric($data['pickup_lat']) && is_numeric($data['pickup_lng'])) {
            $pickup = ['lat' => (float) $data['pickup_lat'], 'lng' => (float) $data['pickup_lng'], 'address' => trim((string) ($data['pickup_address'] ?? '')) ?: 'Patient pickup', 'source' => 'practice'];
        } elseif (! empty($meta['patient_location']['lat'])) {
            $pickup = ['lat' => (float) $meta['patient_location']['lat'], 'lng' => (float) $meta['patient_location']['lng'], 'address' => (string) ($meta['patient_location']['label'] ?? 'Patient\'s current location'), 'source' => 'live_gps'];
        } elseif ($call->patient && $call->patient->lat !== null && $call->patient->lng !== null) {
            $pickup = ['lat' => (float) $call->patient->lat, 'lng' => (float) $call->patient->lng, 'address' => (string) ($call->patient->address ?: 'Patient\'s saved address'), 'source' => 'saved_address'];
        }
        if (! $pickup || abs($pickup['lat']) > 90 || abs($pickup['lng']) > 180) {
            throw new DoctorMatchException('We do not have the patient\'s location yet. Ask them to tap "Share my location" on their call screen, or enter the pickup address.', 422);
        }
        $dropoff = ['lat' => (float) $clinic->lat, 'lng' => (float) $clinic->lng, 'address' => $clinic->shuttleAddress()];
        $quotes = $this->pricing->quotesFor($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']);
        if (! $quotes) {
            throw new DoctorMatchException('No shuttle vehicles are set up (Staff → Ride Service → Rate cards).', 422);
        }
        $meta['shuttle'] = [
            'status' => 'offered',
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'return_leg' => (bool) ($data['return_leg'] ?? PracticeCallSettings::bool('practice_call_shuttle_return_default')),
            'quotes' => array_map(fn ($q) => [
                'vehicle_type' => $q['vehicle_type'],
                'label' => ucwords(str_replace('_', ' ', (string) $q['vehicle_type'])),
                'fare' => (float) $q['fare'],
                'distance_km' => (float) $q['distance_km'],
                'eta_minutes' => (int) $q['eta_minutes'],
                'surge_label' => $q['surge_label'] ?? null,
                'quote_token' => $q['quote_token'],
                'quote_expires_at' => $q['quote_expires_at'],
            ], $quotes),
            'offered_by' => $user->id,
            'offered_at' => now()->toIso8601String(),
        ];
        $call->forceFill(['meta' => $meta])->save();
        \Illuminate\Support\Facades\Cache::put('pc-version:'.$call->id, (string) microtime(true), 3600);

        $patient = $call->patientUser;
        if ($patient) {
            app(InAppNotifier::class)->send($patient, 'appointment', 'Choose your shuttle & pay', 'Your practice offered a shuttle to '.($clinic->name ?? 'the practice').'. Choose a vehicle on your call screen.', [
                'route' => 'practice_call', 'params' => ['call_id' => $call->id], 'url' => app(PracticeCallService::class)->patientUrl($call),
            ]);
        }

        return $this->shuttlePayload($call->fresh(), 'practice');
    }

    /**
     * Patient picks a vehicle → ride(s) created at the locked fare, scheduled for the
     * appointment, linked to it; the normal ride payment sheet takes the payment.
     *
     * @return array<string, mixed>
     */
    public function chooseShuttle(CallLog $call, User $user, string $vehicleType): array
    {
        if (app(PracticeCallService::class)->authorizeParty($call, $user) !== 'patient') {
            throw new DoctorMatchException('Only the patient can choose the shuttle.', 403);
        }
        $result = DB::transaction(function () use ($call, $user, $vehicleType) {
            $c = CallLog::query()->whereKey($call->id)->lockForUpdate()->first();
            $meta = (array) $c->meta;
            $sh = (array) ($meta['shuttle'] ?? []);
            if (($sh['status'] ?? null) !== 'offered') {
                throw new DoctorMatchException(($sh['status'] ?? null) === 'chosen' ? 'You already chose a shuttle.' : 'There is no shuttle offer to choose from.', 409);
            }
            $q = collect((array) ($sh['quotes'] ?? []))->firstWhere('vehicle_type', $vehicleType);
            if (! $q) {
                throw new DoctorMatchException('Choose one of the offered vehicles.', 422);
            }
            $p = $sh['pickup'];
            $d = $sh['dropoff'];
            $locked = $this->pricing->verifyQuote($q['quote_token'] ?? null, $vehicleType, (float) $p['lat'], (float) $p['lng'], (float) $d['lat'], (float) $d['lng']);
            if (! $locked) {
                // Quote expired: re-price (same route) so the patient pays a current fare; ask them to confirm.
                $fresh = $this->pricing->quoteOne($vehicleType, (float) $p['lat'], (float) $p['lng'], (float) $d['lat'], (float) $d['lng']);
                if ($fresh) {
                    $sh['quotes'] = array_map(fn ($x) => $x['vehicle_type'] === $vehicleType ? array_merge($x, ['fare' => (float) $fresh['fare'], 'quote_token' => $fresh['quote_token'], 'quote_expires_at' => $fresh['quote_expires_at']]) : $x, (array) $sh['quotes']);
                    $meta['shuttle'] = $sh;
                    $c->forceFill(['meta' => $meta])->save();
                }
                throw new DoctorMatchException('The price was updated'.($fresh ? ' to R'.number_format((float) $fresh['fare'], 2) : '').' — please confirm your choice again.', 409);
            }
            $patient = $user->patientProfile;
            $appt = $c->appointment;
            $pickupAt = $this->pickupTime($appt, (int) ($q['eta_minutes'] ?? 0));
            $ride = app(RideDispatchService::class)->requestRide(
                patient: $patient,
                pickupAddress: (string) $p['address'],
                pickupLat: (float) $p['lat'],
                pickupLng: (float) $p['lng'],
                dropoffAddress: (string) $d['address'],
                dropoffLat: (float) $d['lat'],
                dropoffLng: (float) $d['lng'],
                appointmentId: $appt?->id,
                vehicleType: $vehicleType,
                autoAssign: true,
                lockedQuote: $locked,
                scheduledFor: $pickupAt,
                pickupNotes: 'Booked on an urgent practice call'.($appt?->clinic?->name ? ' for '.$appt->clinic->name : ''),
                bookedByUserId: $user->id,
            );
            $return = null;
            if (! empty($sh['return_leg']) && $appt) {
                $back = Carbon::parse($appt->date->toDateString().' '.substr((string) ($appt->end_time ?: $appt->start_time), 0, 5), Appointment::CLINIC_TIMEZONE)->addMinutes(15);
                $return = app(RideSeriesService::class)->requestReturnLeg($ride, $back->toIso8601String());
            }
            if ($appt) {
                $appt->forceFill(['ride_requested' => true])->saveQuietly();
            }
            $sh['status'] = 'chosen';
            $sh['vehicle_type'] = $vehicleType;
            $sh['fare'] = (float) $ride->quoted_fare;
            $sh['ride_id'] = $ride->id;
            $sh['return_ride_id'] = $return?->id;
            $sh['pickup_at'] = $pickupAt?->toIso8601String();
            $sh['chosen_at'] = now()->toIso8601String();
            $meta['shuttle'] = $sh;
            $c->forceFill(['meta' => $meta])->save();

            return [$c, $ride];
        });
        [$c, $ride] = $result;
        \Illuminate\Support\Facades\Cache::put('pc-version:'.$c->id, (string) microtime(true), 3600);
        if ($c->practiceUser) {
            app(InAppNotifier::class)->send($c->practiceUser, 'appointment', 'Shuttle chosen', ($c->patientUser?->name ?? 'The patient').' chose a '.str_replace('_', ' ', $vehicleType).' (R'.number_format((float) $ride->quoted_fare, 2).').', ['route' => 'practice_call', 'params' => ['call_id' => $c->id]]);
        }

        return $this->shuttlePayload($c->fresh(), 'patient');
    }

    /** Pickup = appointment start − route ETA − buffer (never in the past). */
    public function pickupTime(?Appointment $appt, int $etaMinutes): ?Carbon
    {
        if (! $appt || $appt->status !== Appointment::STATUS_CONFIRMED || ! $appt->date || ! $appt->start_time) {
            return null; // not booked yet: an on-demand ride now
        }
        $start = Carbon::parse($appt->date->toDateString().' '.substr((string) $appt->start_time, 0, 5), Appointment::CLINIC_TIMEZONE);
        $at = $start->copy()->subMinutes(max(0, $etaMinutes) + max(0, PracticeCallSettings::int('practice_call_shuttle_buffer_minutes')));

        return $at->lt(now()->addMinutes(2)) ? null : $at->utc();
    }

    /** The booking moved: a still-scheduled shuttle follows it. */
    protected function retimeShuttle(CallLog $call, Appointment $appt): void
    {
        $sh = (array) (((array) $call->meta)['shuttle'] ?? []);
        if (($sh['status'] ?? null) !== 'chosen' || empty($sh['ride_id'])) {
            return;
        }
        try {
            $ride = Ride::query()->find((int) $sh['ride_id']);
            if ($ride && $ride->status === Ride::STATUS_REQUESTED && ! $ride->driver_profile_id) {
                $at = $this->pickupTime($appt, (int) ($ride->route_duration_min ?? $ride->eta_minutes ?? 0));
                $ride->update(['scheduled_for' => $at]);
            }
        } catch (\Throwable $e) {
            Log::warning('[PracticeBooking] retime shuttle: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    public function shuttlePayload(CallLog $call, string $role): ?array
    {
        $sh = (array) (((array) $call->meta)['shuttle'] ?? []);
        if (! $sh) {
            return null;
        }
        $ride = ! empty($sh['ride_id']) ? Ride::query()->find((int) $sh['ride_id']) : null;
        $payment = $ride ? app(RidePaymentService::class)->payload($ride) : null;

        return [
            'status' => $sh['status'] ?? 'offered',
            'pickup' => $sh['pickup'] ?? null,
            'dropoff' => $sh['dropoff'] ?? null,
            'return_leg' => (bool) ($sh['return_leg'] ?? false),
            'quotes' => array_map(fn ($q) => array_diff_key($q, $role === 'patient' ? [] : ['quote_token' => 1]), (array) ($sh['quotes'] ?? [])),
            'vehicle_type' => $sh['vehicle_type'] ?? null,
            'fare' => isset($sh['fare']) ? (float) $sh['fare'] : null,
            'pickup_at' => $sh['pickup_at'] ?? null,
            'ride' => $ride ? [
                'id' => $ride->id,
                'ride_ref' => $ride->ride_ref,
                'status' => $ride->status,
                'scheduled_for' => $ride->scheduled_for?->toIso8601String(),
                'fare' => (float) ($ride->quoted_fare ?? 0),
                'return_ride_id' => $sh['return_ride_id'] ?? null,
                'track_url' => $role === 'patient' && Route::has('rides.track') ? route('rides.track', $ride) : null,
                'pay_url' => $role === 'patient' && $payment && $payment['required'] && Route::has('rides.track') ? route('rides.track', $ride).'?pay=ride:'.$ride->id : null,
            ] : null,
            'payment' => $payment,
        ];
    }
}
