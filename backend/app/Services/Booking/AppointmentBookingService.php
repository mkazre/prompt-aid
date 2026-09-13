<?php

namespace App\Services\Booking;

use App\Contracts\NotificationDispatcherInterface;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AppointmentBookingService
{
    public function __construct(protected NotificationDispatcherInterface $notifier) {}

    /**
     * Available start times for a doctor at a clinic on a given date,
     * derived from their weekly availability minus already-booked slots
     * and time off.
     *
     * @return Collection<int, string> list of "H:i" start times
     */
    public function availableSlots(DoctorProfile $doctor, int $clinicId, string $date): Collection
    {
        $day = Carbon::parse($date);
        $weekday = $day->dayOfWeek;

        $hasTimeOff = $doctor->timeOff()->whereDate('date', $day)->exists();

        if ($hasTimeOff) {
            return collect();
        }

        $availability = $doctor->availabilities()
            ->where('clinic_id', $clinicId)
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->first();

        if (! $availability) {
            return collect();
        }

        $booked = Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->where('clinic_id', $clinicId)
            ->whereDate('date', $day)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->pluck('start_time')
            ->map(fn ($t) => substr($t, 0, 5))
            ->all();

        $slots = collect();
        $cursor = Carbon::parse($availability->start_time);
        $end = Carbon::parse($availability->end_time);

        while ($cursor->lt($end)) {
            $slot = $cursor->format('H:i');

            if (! in_array($slot, $booked, true) && (! $day->isToday() || $cursor->gt(now()))) {
                $slots->push($slot);
            }

            $cursor->addMinutes($availability->slot_minutes);
        }

        return $slots;
    }

    public function book(
        PatientProfile $patient,
        DoctorProfile $doctor,
        int $clinicId,
        ?int $serviceId,
        string $date,
        string $startTime,
        string $visitType = 'clinic',
        ?string $reason = null,
    ): Appointment {
        $slots = $this->availableSlots($doctor, $clinicId, $date);

        if (! $slots->contains($startTime)) {
            throw new \RuntimeException('The selected time slot is no longer available.');
        }

        $availability = $doctor->availabilities()->where('clinic_id', $clinicId)->where('weekday', Carbon::parse($date)->dayOfWeek)->first();
        $duration = $availability?->slot_minutes ?? 30;
        $endTime = Carbon::parse($startTime)->addMinutes($duration)->format('H:i');

        $appointment = Appointment::query()->create([
            'patient_profile_id' => $patient->id,
            'doctor_profile_id' => $doctor->id,
            'clinic_id' => $clinicId,
            'service_id' => $serviceId,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'visit_type' => $visitType,
            'status' => Appointment::STATUS_PENDING,
            'reason' => $reason,
        ]);

        $this->notifier->email(
            $patient->user,
            'Appointment booked',
            "Your appointment with Dr. {$doctor->user->name} on {$date} at {$startTime} has been booked and is pending confirmation."
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
            "Your appointment on {$appointment->date->format('Y-m-d')} at {$appointment->start_time} has been cancelled."
        );

        return $appointment;
    }
}
