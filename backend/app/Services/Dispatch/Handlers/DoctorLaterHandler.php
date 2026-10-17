<?php

namespace App\Services\Dispatch\Handlers;

use App\Models\Appointment;
use App\Models\DispatchJob;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\Dispatch\AvailabilityService;
use App\Services\Dispatch\DispatchException;
use App\Services\Dispatch\DispatchService;
use App\Support\DispatchSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Any doctor of specialty X, later today / tomorrow": the patient picks a
 * time window; every doctor of that specialty whose LATER / TOMORROW /
 * weekly hours cover it (and, in person, whose rooms are within the
 * radius) gets the alert. First accept gets the appointment.
 *
 * Subject: an Appointment in status "matching" with no doctor yet.
 */
class DoctorLaterHandler extends BaseHandler
{
    public function service(): string
    {
        return 'doctor_later';
    }

    protected function appt(DispatchJob $job): ?Appointment
    {
        return Appointment::query()->with(['patient.user'])->find($job->subject_id);
    }

    public function candidates(DispatchJob $job): Collection
    {
        $a = $this->appt($job);
        if (! $a || ! $job->window_from) {
            return collect();
        }
        $avail = app(AvailabilityService::class);
        $from = $job->window_from;
        $to = $job->window_to ?? $from->copy()->addHour();
        $patientUserId = $a->patient?->user_id;

        $doctors = DoctorProfile::query()->with(['user', 'clinics'])
            ->where('status', 'active')->where('is_accepting_appointments', true)
            ->where('specialization', $job->capability)
            ->when($patientUserId, fn ($q) => $q->where('user_id', '!=', $patientUserId))
            ->limit(300)->get();

        $rows = collect();
        foreach ($doctors as $doctor) {
            if (! $doctor->user) {
                continue;
            }
            $ok = $avail->qualifiesAt($doctor->user, $from, $to) || $this->weeklyCovers($doctor, $from);
            if (! $ok) {
                continue;
            }
            $distance = null;
            if ($a->mode === Appointment::MODE_IN_PERSON && $job->lat !== null) {
                $clinic = $doctor->clinics->filter(fn ($c) => $c->lat !== null && $c->lng !== null)
                    ->sortBy(fn ($c) => \App\Services\Geo\RoutingService::haversineKm((float) $job->lat, (float) $job->lng, (float) $c->lat, (float) $c->lng))->first();
                if (! $clinic) {
                    continue;
                }
                $distance = $this->distanceKm((float) $job->lat, (float) $job->lng, (float) $clinic->lat, (float) $clinic->lng);
                if ($distance > $this->radiusFor($job, $avail->maxRadius($doctor->user_id))) {
                    continue;
                }
            }
            $rows->push(['user' => $doctor->user, 'distance_km' => $distance, 'rating' => (float) $doctor->rating_avg]);
        }

        return $rows->sortBy([['distance_km', 'asc'], ['rating', 'desc']])->values()->map(fn ($r) => ['user' => $r['user'], 'distance_km' => $r['distance_km']]);
    }

    protected function weeklyCovers(DoctorProfile $doctor, Carbon $at): bool
    {
        $local = $at->copy()->setTimezone(Appointment::CLINIC_TIMEZONE);
        $t = $local->format('H:i:s');

        return DoctorAvailability::query()->where('doctor_profile_id', $doctor->id)->where('is_active', true)
            ->where('weekday', $local->dayOfWeek)->where('start_time', '<=', $t)->where('end_time', '>', $t)->exists();
    }

    public function offerTtl(DispatchJob $job): int
    {
        return max(DispatchSettings::offerTimeout(), 600);
    }

    public function stillOpen(DispatchJob $job): bool
    {
        $a = Appointment::query()->find($job->subject_id);

        return $a !== null && $a->status === Appointment::STATUS_MATCHING && ! $a->doctor_profile_id;
    }

    public function onAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $a = Appointment::query()->whereKey($job->subject_id)->lockForUpdate()->first();
        $doctor = $provider->doctorProfile;
        if (! $a || $a->status !== Appointment::STATUS_MATCHING || $a->doctor_profile_id) {
            throw new DispatchException('This request was already taken.', 409);
        }
        if (! $doctor) {
            throw new DispatchException('Only doctors can accept this request.', 403);
        }
        $clinic = null;
        if ($a->mode === Appointment::MODE_IN_PERSON && $job->lat !== null) {
            $clinic = $doctor->clinics()->whereNotNull('lat')->get()
                ->sortBy(fn ($c) => \App\Services\Geo\RoutingService::haversineKm((float) $job->lat, (float) $job->lng, (float) $c->lat, (float) $c->lng))->first();
        }
        $clinicId = $clinic?->id ?? $doctor->clinics()->value('clinics.id');
        $start = ($job->window_from ?? now())->copy()->setTimezone(Appointment::CLINIC_TIMEZONE);
        $a->update([
            'doctor_profile_id' => $doctor->id,
            'clinic_id' => $clinicId,
            'date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
    }

    public function afterAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $a = $this->appt($job);
        $when = ($job->window_from ?? now())->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->format('D j M, H:i');
        $this->notifyUser($a?->patient?->user, 'appointment', 'Doctor found', "Dr. {$provider->name} accepted your request for {$when}.", [
            'route' => 'appointment', 'params' => ['appointment_id' => $job->subject_id], 'url' => '/account/appointments',
        ]);
    }

    public function onExpired(DispatchJob $job): void
    {
        $a = $this->appt($job);
        if ($a && $a->status === Appointment::STATUS_MATCHING) {
            $a->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => 'No doctor accepted in time — you were not charged']);
            $this->notifyUser($a->patient?->user, 'appointment', 'No doctor available', 'No doctor accepted your request in time. Try a wider radius, another time, or book a slot.', [
                'route' => 'appointment', 'params' => ['appointment_id' => $a->id], 'url' => '/account/request-doctor/'.$job->id,
            ]);
        }
    }

    public function card(DispatchJob $job): array
    {
        $a = $this->appt($job);
        $when = $job->window_from?->copy()->setTimezone(Appointment::CLINIC_TIMEZONE);
        $isTomorrow = $when && $when->toDateString() !== now()->setTimezone(Appointment::CLINIC_TIMEZONE)->toDateString();

        return [
            'icon' => 'stethoscope',
            'title' => ($isTomorrow ? 'Tomorrow' : 'Later today').' · '.$job->capability,
            'subtitle' => ($a?->mode === Appointment::MODE_VIDEO ? 'Video consult' : 'In-person visit').($when ? ' · '.$when->format('D j M, H:i') : ''),
            'patient_first_name' => $this->firstName($a?->patient?->user?->name),
            'lines' => array_values(array_filter([
                $job->window_to ? 'Window '.$when?->format('H:i').'–'.$job->window_to->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->format('H:i') : null,
                $a?->reason ? 'Reason: '.mb_substr((string) $a->reason, 0, 120) : null,
            ])),
            'earning' => isset($job->meta['price']) ? (float) $job->meta['price'] : null,
            'when' => $job->window_from?->toIso8601String(),
            'url' => '/staff/appointments',
        ];
    }

    public function canServe(DispatchJob $job, User $provider): bool
    {
        return $provider->role === User::ROLE_DOCTOR && $provider->doctorProfile?->status === 'active';
    }

    /**
     * Patient request: creates the "matching" appointment and opens the job.
     *
     * @return DispatchJob
     */
    public static function request(\App\Models\PatientProfile $patient, string $specialty, string $mode, Carbon $from, Carbon $to, ?float $lat, ?float $lng, ?float $radiusKm, ?string $reason = null): DispatchJob
    {
        $specialty = trim($specialty);
        if (! in_array($mode, [Appointment::MODE_VIDEO, Appointment::MODE_IN_PERSON], true)) {
            throw new DispatchException('Choose video or in person.');
        }
        if (! in_array(mb_strtolower($specialty), array_map('mb_strtolower', \App\Services\Doctors\DoctorMatchService::specialties()), true)) {
            throw new DispatchException('We don\'t have doctors in that specialty yet.');
        }
        if ($mode === Appointment::MODE_IN_PERSON && ($lat === null || $lng === null)) {
            throw new DispatchException('We need your location to find doctors near you.');
        }
        if ($from->lt(now()->subMinutes(5)) || $to->lte($from) || $from->gt(now()->addDays(2)->endOfDay())) {
            throw new DispatchException('Choose a time window later today or tomorrow.');
        }
        $open = DispatchJob::query()->where('service', 'doctor_later')->where('patient_user_id', $patient->user_id)->whereIn('status', DispatchJob::OPEN)->exists();
        if ($open) {
            throw new DispatchException('You already have a doctor request waiting for a doctor.', 409);
        }

        $local = $from->copy()->setTimezone(Appointment::CLINIC_TIMEZONE);
        $appointment = Appointment::query()->create([
            'patient_profile_id' => $patient->id,
            'doctor_profile_id' => null,
            'clinic_id' => null,
            'date' => $local->toDateString(),
            'start_time' => $local->format('H:i:s'),
            'end_time' => $local->copy()->addMinutes(30)->format('H:i:s'),
            'visit_type' => $mode === Appointment::MODE_VIDEO ? 'telemed' : 'clinic',
            'status' => Appointment::STATUS_MATCHING,
            'mode' => $mode,
            'requested_specialty' => $specialty,
            'reason' => $reason ?: 'Any available doctor',
        ]);

        $expiry = min(
            $from->getTimestamp(),
            now()->addMinutes(max(15, DispatchSettings::int('dispatch_scheduled_expiry_minutes')))->getTimestamp(),
        );

        return app(DispatchService::class)->open('doctor_later', $appointment, [
            'patient_user_id' => $patient->user_id,
            'lat' => $mode === Appointment::MODE_IN_PERSON ? $lat : null,
            'lng' => $mode === Appointment::MODE_IN_PERSON ? $lng : null,
            'radius_km' => DispatchSettings::clampRadius($radiusKm),
            'capability' => $specialty,
            'window_from' => $from,
            'window_to' => $to,
            'expires_at' => Carbon::createFromTimestamp(max($expiry, now()->addMinutes(10)->getTimestamp())),
        ]);
    }
}
