<?php

namespace App\Services\Dispatch\Handlers;

use App\Models\Appointment;
use App\Models\DispatchJob;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\Dispatch\DispatchException;
use App\Support\DispatchSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Patient booked a slot with a SPECIFIC doctor (status pending): that
 * doctor gets an Accept / Decline alert. Accept = confirmed; decline =
 * cancelled with a clear reason so the patient can rebook. If the doctor
 * doesn't respond before the slot, the booking stays pending (they can
 * still confirm from Appointments) and admins are told.
 */
class AppointmentHandler extends BaseHandler
{
    public function service(): string
    {
        return 'appointment';
    }

    protected function appt(DispatchJob $job): ?Appointment
    {
        return Appointment::query()->with(['patient.user', 'doctor.user', 'clinic', 'service'])->find($job->subject_id);
    }

    public static function startsAt(Appointment $a): Carbon
    {
        return Carbon::parse($a->date->toDateString().' '.substr((string) $a->start_time, 0, 5), Appointment::CLINIC_TIMEZONE)->utc();
    }

    public function candidates(DispatchJob $job): Collection
    {
        $a = $this->appt($job);
        $user = $a?->doctor?->user;

        return $user ? collect([['user' => $user, 'distance_km' => null]]) : collect();
    }

    public function offerTtl(DispatchJob $job): int
    {
        $a = $this->appt($job);
        $until = $a ? self::startsAt($a) : now()->addHour();

        return (int) max(300, min(12 * 3600, now()->diffInSeconds($until, false)));
    }

    public function stillOpen(DispatchJob $job): bool
    {
        $a = Appointment::query()->find($job->subject_id);

        return $a !== null && $a->status === Appointment::STATUS_PENDING;
    }

    public function onAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $a = Appointment::query()->whereKey($job->subject_id)->lockForUpdate()->first();
        if (! $a || $a->status !== Appointment::STATUS_PENDING) {
            throw new DispatchException('This booking was already handled.', 409);
        }
        $doctor = $provider->doctorProfile;
        if (! $doctor) {
            throw new DispatchException('Only doctors can accept appointments.', 403);
        }
        $updates = ['status' => Appointment::STATUS_CONFIRMED];
        if ((int) $a->doctor_profile_id !== (int) $doctor->id) {
            $updates['doctor_profile_id'] = $doctor->id; // manual reassignment by an admin
        }
        $a->update($updates);
    }

    public function afterAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $a = $this->appt($job);
        if (! $a) {
            return;
        }
        $when = self::startsAt($a)->setTimezone(Appointment::CLINIC_TIMEZONE)->format('D j M, H:i');
        $this->notifyUser($a->patient?->user, 'appointment', 'Appointment confirmed', "Dr. {$provider->name} confirmed your appointment on {$when}.", [
            'route' => 'appointment', 'params' => ['appointment_id' => $a->id], 'url' => '/account/appointments',
        ]);
    }

    public function onDeclined(DispatchJob $job, User $provider, ?string $reason): void
    {
        $a = $this->appt($job);
        if (! $a || $a->status !== Appointment::STATUS_PENDING || (int) $a->doctor?->user_id !== (int) $provider->id) {
            return;
        }
        $a->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => 'The doctor is not available at this time'.($reason ? ' ('.$reason.')' : '').'. Please choose another time or doctor.']);
        app(\App\Services\Dispatch\DispatchService::class)->close($job, DispatchJob::CANCELLED, 'declined_by_provider');
        $this->notifyUser($a->patient?->user, 'appointment', 'Please choose another time', "Dr. {$provider->name} can't see you at the booked time. Your booking was cancelled at no charge — please pick another slot or doctor.", [
            'route' => 'appointment', 'params' => ['appointment_id' => $a->id], 'url' => '/account/appointments',
        ]);
    }

    public function onExpired(DispatchJob $job): void
    {
        $this->alertAdmins($job, 'Doctor has not confirmed a booking');
    }

    public function card(DispatchJob $job): array
    {
        $a = $this->appt($job);
        if (! $a) {
            return ['icon' => 'calendar', 'title' => 'Appointment request', 'subtitle' => 'No longer available', 'patient_first_name' => null, 'lines' => [], 'earning' => null, 'when' => null, 'url' => null];
        }
        $start = self::startsAt($a);
        $mode = $a->mode === Appointment::MODE_VIDEO ? 'Video consult' : 'In-person visit';
        $fee = $a->service?->price ?? $a->doctor?->consultation_fee;

        return [
            'icon' => 'calendar',
            'title' => 'Appointment request',
            'subtitle' => $mode.' · '.$start->copy()->setTimezone(Appointment::CLINIC_TIMEZONE)->format('D j M, H:i'),
            'patient_first_name' => $this->firstName($a->patient?->user?->name),
            'lines' => array_values(array_filter([
                $a->clinic?->name ? 'At '.$a->clinic->name : null,
                $a->reason ? 'Reason: '.mb_substr((string) $a->reason, 0, 120) : null,
            ])),
            'earning' => $fee !== null ? (float) $fee : null,
            'when' => $start->toIso8601String(),
            'url' => '/staff/appointments',
        ];
    }

    public function canServe(DispatchJob $job, User $provider): bool
    {
        return $provider->role === User::ROLE_DOCTOR && $provider->doctorProfile?->status === 'active';
    }

    /** Called by the Appointment observer for new patient bookings. */
    public static function open(Appointment $a): void
    {
        if ($a->status !== Appointment::STATUS_PENDING || ! $a->doctor_profile_id) {
            return;
        }
        $start = self::startsAt($a);
        if ($start->isPast()) {
            return;
        }
        $a->loadMissing('patient');
        app(\App\Services\Dispatch\DispatchService::class)->open('appointment', $a, [
            'patient_user_id' => $a->patient?->user_id,
            'target_user_id' => DoctorProfile::query()->whereKey($a->doctor_profile_id)->value('user_id'),
            'window_from' => $start,
            'expires_at' => $start,
            'meta' => ['mode_label' => $a->mode],
        ]);
    }
}
