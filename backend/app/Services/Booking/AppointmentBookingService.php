<?php

namespace App\Services\Booking;

use App\Contracts\NotificationDispatcherInterface;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\DoctorTimeOff;
use App\Models\PatientProfile;
use App\Support\StaffNotifier;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AppointmentBookingService
{
    public function __construct(protected NotificationDispatcherInterface $notifier) {}

    /**
     * Time-off rows covering a given day. A row covers `date` through
     * `end_date` (inclusive; null end_date = single day). A row with no
     * start/end time blocks the whole day, otherwise just that window.
     *
     * @return Collection<int, DoctorTimeOff>
     */
    public function timeOffOn(DoctorProfile $doctor, Carbon $day): Collection
    {
        $d = $day->toDateString();

        return $doctor->timeOff()
            ->whereDate('date', '<=', $d)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->whereNull('end_date')->whereDate('date', $d))
                ->orWhereDate('end_date', '>=', $d))
            ->get();
    }

    /**
     * The full slot grid for a doctor at a clinic on a date, grouped by the
     * doctor's availability sessions for that weekday. Every slot carries
     * flags so clients can render booked/past slots (struck through)
     * alongside free ones.
     *
     * @return array{date: string, time_off: ?string, sessions: array<int, array{label: string, start: string, end: string, slot_minutes: int, slots: array<int, array{time: string, available: bool, booked: bool, past: bool, blocked: bool}>}>}
     */
    public function slotSchedule(DoctorProfile $doctor, int $clinicId, string $date, ?int $ignoreAppointmentId = null): array
    {
        $day = Carbon::parse($date)->startOfDay();

        $timeOff = $this->timeOffOn($doctor, $day);
        $fullDayOff = $timeOff->first(fn (DoctorTimeOff $t) => ! $t->start_time || ! $t->end_time);

        if ($fullDayOff) {
            return [
                'date' => $day->toDateString(),
                'time_off' => $fullDayOff->reason ?: 'Doctor unavailable',
                'sessions' => [],
            ];
        }

        $sessions = $doctor->availabilities()
            ->where('clinic_id', $clinicId)
            ->where('weekday', $day->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        // Booked intervals at ANY clinic — a doctor can't be in two places.
        $booked = Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->whereDate('date', $day)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId))
            ->get(['start_time', 'end_time'])
            ->map(fn ($a) => [
                $this->minutes($a->start_time),
                $a->end_time ? $this->minutes($a->end_time) : $this->minutes($a->start_time) + 1,
            ])
            ->all();

        $blocks = $timeOff
            ->map(fn (DoctorTimeOff $t) => [$this->minutes($t->start_time), $this->minutes($t->end_time)])
            ->all();

        $localNow = now(\App\Models\Appointment::CLINIC_TIMEZONE);
        $nowMinutes = $day->toDateString() === $localNow->toDateString() ? $localNow->hour * 60 + $localNow->minute : null;
        $isPastDay = $day->toDateString() < $localNow->toDateString();

        $result = [];

        foreach ($sessions->values() as $i => $session) {
            $step = max(5, (int) $session->slot_minutes);
            $start = $this->minutes($session->start_time);
            $end = $this->minutes($session->end_time);
            $slots = [];

            for ($t = $start; $t < $end; $t += $step) {
                $slotEnd = min($t + $step, $end);
                $isBooked = $this->overlaps($t, $slotEnd, $booked);
                $isBlocked = $this->overlaps($t, $slotEnd, $blocks);
                $isPast = $isPastDay || ($nowMinutes !== null && $t <= $nowMinutes);

                $slots[] = [
                    'time' => $this->hm($t),
                    'available' => ! $isBooked && ! $isBlocked && ! $isPast,
                    'booked' => $isBooked,
                    'past' => $isPast,
                    'blocked' => $isBlocked,
                ];
            }

            $result[] = [
                'label' => 'Session '.($i + 1),
                'start' => $this->hm($start),
                'end' => $this->hm($end),
                'slot_minutes' => $step,
                'slots' => $slots,
            ];
        }

        return ['date' => $day->toDateString(), 'time_off' => null, 'sessions' => $result];
    }

    /**
     * Available start times for a doctor at a clinic on a given date,
     * derived from their weekly availability sessions minus booked slots
     * and time off.
     *
     * @return Collection<int, string> list of "H:i" start times
     */
    public function availableSlots(DoctorProfile $doctor, int $clinicId, string $date, ?int $ignoreAppointmentId = null): Collection
    {
        return collect($this->slotSchedule($doctor, $clinicId, $date, $ignoreAppointmentId)['sessions'])
            ->flatMap(fn ($s) => $s['slots'])
            ->where('available', true)
            ->pluck('time')
            ->unique()
            ->values();
    }

    /**
     * Slot length for a start time — from the availability session that
     * contains it, falling back to 30 minutes.
     */
    protected function slotMinutesFor(DoctorProfile $doctor, int $clinicId, string $date, string $startTime): int
    {
        $t = $this->minutes($startTime);

        $session = $doctor->availabilities()
            ->where('clinic_id', $clinicId)
            ->where('weekday', Carbon::parse($date)->dayOfWeek)
            ->where('is_active', true)
            ->get()
            ->first(fn ($s) => $t >= $this->minutes($s->start_time) && $t < $this->minutes($s->end_time));

        return (int) ($session?->slot_minutes ?? 30);
    }

    /**
     * @param  array{status?: string, by_doctor?: bool}  $options
     */
    public function book(
        PatientProfile $patient,
        DoctorProfile $doctor,
        int $clinicId,
        ?int $serviceId,
        string $date,
        string $startTime,
        string $visitType = 'clinic',
        ?string $reason = null,
        array $options = [],
    ): Appointment {
        $startTime = substr($startTime, 0, 5);
        $slots = $this->availableSlots($doctor, $clinicId, $date);

        if (! $slots->contains($startTime)) {
            throw new \RuntimeException('The selected time slot is no longer available.');
        }

        $duration = $this->slotMinutesFor($doctor, $clinicId, $date, $startTime);
        $endTime = Carbon::parse($startTime)->addMinutes($duration)->format('H:i');
        $byDoctor = (bool) ($options['by_doctor'] ?? false);

        // Call back: the doctor phones / WhatsApp-calls the patient at the slot time.
        $callback = [];
        if ($visitType === Appointment::VISIT_PHONE) {
            $phone = \App\Services\Consults\ConsultCallService::normalisePhone($options['callback_phone'] ?? null)
                ?? \App\Services\Consults\ConsultCallService::normalisePhone($patient->user?->phone);
            if (! $phone) {
                throw new \RuntimeException('Enter the phone number the doctor should call.');
            }
            $note = trim(strip_tags((string) ($options['callback_note'] ?? '')));
            $callback = [
                'callback_phone' => $phone,
                'callback_channel' => in_array($options['callback_channel'] ?? null, Appointment::CALLBACK_CHANNELS, true) ? $options['callback_channel'] : 'either',
                'callback_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            ];
        }

        $appointment = Appointment::query()->create($callback + [
            'patient_profile_id' => $patient->id,
            'doctor_profile_id' => $doctor->id,
            'clinic_id' => $clinicId,
            'service_id' => $serviceId,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'visit_type' => $visitType,
            'mode' => match ($visitType) {
                'telemed' => Appointment::MODE_VIDEO,
                Appointment::VISIT_PHONE => Appointment::MODE_CALLBACK,
                default => Appointment::MODE_IN_PERSON,
            },
            'status' => $options['status'] ?? Appointment::STATUS_PENDING,
            'reason' => $reason,
        ]);

        if ($byDoctor) {
            $this->notifier->email(
                $patient->user,
                'Appointment booked for you',
                "Dr. {$doctor->user->name} booked an appointment for you on {$date} at {$startTime}.",
                ['type' => 'appointment', 'data' => ['route' => 'appointment', 'params' => ['appointment_id' => $appointment->id], 'url' => '/account/appointments']]
            );
        } else {
            $this->notifier->email(
                $patient->user,
                'Appointment booked',
                "Your appointment with Dr. {$doctor->user->name} on {$date} at {$startTime} has been booked and is pending confirmation.",
                ['type' => 'appointment', 'data' => ['route' => 'appointment', 'params' => ['appointment_id' => $appointment->id], 'url' => '/account/appointments']]
            );

            // In-app card for the doctor (StaffNotifier only feeds the Filament bell).
            app(\App\Services\Notifications\InAppNotifier::class)->send(
                $doctor->user,
                'appointment',
                'New appointment booking',
                "{$patient->user->name} booked a {$visitType} visit on {$date} at {$startTime}.",
                ['route' => 'doctor_appointment', 'params' => ['appointment_id' => $appointment->id, 'date' => $date]]
            );

            StaffNotifier::alert(
                $doctor->user,
                'New appointment booking',
                "{$patient->user->name} booked a {$visitType} visit on {$date} at {$startTime}.",
                icon: 'heroicon-o-calendar-days',
                color: 'warning',
                url: route('filament.admin.resources.appointments.index'),
                actionLabel: 'Review',
            );
        }

        return $appointment;
    }

    /**
     * Move an appointment to a new free slot (same doctor and clinic).
     */
    /**
     * @param  array<string, mixed>  $emailContext  extra dispatcher context for the
     *                                              patient email (e.g. ['in_app' => false] when the caller sends its own in-app card)
     */
    public function reschedule(Appointment $appointment, string $date, string $startTime, array $emailContext = []): Appointment
    {
        $startTime = substr($startTime, 0, 5);
        $doctor = $appointment->doctor;
        $clinicId = (int) $appointment->clinic_id;

        $slots = $this->availableSlots($doctor, $clinicId, $date, $appointment->id);

        if (! $slots->contains($startTime)) {
            throw new \RuntimeException('The selected time slot is not available.');
        }

        $duration = $this->slotMinutesFor($doctor, $clinicId, $date, $startTime);

        $appointment->update([
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => Carbon::parse($startTime)->addMinutes($duration)->format('H:i'),
        ]);

        $this->notifier->email(
            $appointment->patient->user,
            'Appointment rescheduled',
            "Your appointment with Dr. {$doctor->user->name} has been moved to {$date} at {$startTime}.",
            $emailContext,
        );

        return $appointment;
    }

    public function cancel(Appointment $appointment, ?string $reason = null): Appointment
    {
        $appointment->update([
            'status' => Appointment::STATUS_CANCELLED,
            'cancel_reason' => $reason,
        ]);

        $this->notifier->email(
            $appointment->patient->user,
            'Appointment cancelled',
            "Your appointment on {$appointment->date->format('Y-m-d')} at {$appointment->start_time} has been cancelled.",
            ['type' => 'appointment', 'data' => ['route' => 'appointment', 'params' => ['appointment_id' => $appointment->id], 'url' => '/account/appointments']]
        );

        if ($appointment->doctor?->user) {
            app(\App\Services\Notifications\InAppNotifier::class)->send(
                $appointment->doctor->user,
                'appointment',
                'Appointment cancelled',
                "{$appointment->patient->user->name} cancelled the {$appointment->date->format('Y-m-d')} {$appointment->start_time} appointment.",
                ['route' => 'doctor_appointment', 'params' => ['appointment_id' => $appointment->id, 'date' => $appointment->date->format('Y-m-d')]]
            );
        }

        return $appointment;
    }

    protected function minutes(?string $time): int
    {
        if (! $time) {
            return 0;
        }

        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));

        return $h * 60 + $m;
    }

    protected function hm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $intervals
     */
    protected function overlaps(int $start, int $end, array $intervals): bool
    {
        foreach ($intervals as [$s, $e]) {
            if ($start < $e && $end > $s) {
                return true;
            }
        }

        return false;
    }
}
