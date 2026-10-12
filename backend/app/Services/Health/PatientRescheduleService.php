<?php

namespace App\Services\Health;

use App\Models\Appointment;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Notifications\InAppNotifier;
use App\Support\HealthSettings;
use App\Support\StaffNotifier;
use Carbon\Carbon;

/**
 * Patient-initiated reschedule: same doctor, same clinic, any free slot.
 * Wraps AppointmentBookingService::reschedule (slot validation) and adds
 * the notifications: the doctor via StaffNotifier, the patient in-app.
 */
class PatientRescheduleService
{
    public function __construct(protected AppointmentBookingService $booking) {}

    public static function startsAt(Appointment $appointment): ?Carbon
    {
        if (! $appointment->date || ! $appointment->start_time) {
            return null;
        }

        return Carbon::parse($appointment->date->toDateString().' '.substr((string) $appointment->start_time, 0, 5), HealthSettings::TIMEZONE);
    }

    /** Why this appointment can't be moved by the patient, or null when it can. */
    public function blockReason(Appointment $appointment): ?string
    {
        if (! HealthSettings::feature('reschedule')) {
            return 'Online rescheduling is not available right now. Please contact the practice.';
        }
        if (! in_array($appointment->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
            return 'Only pending or confirmed appointments can be moved.';
        }
        if (! $appointment->doctor_profile_id || ! $appointment->clinic_id) {
            return 'This appointment can’t be moved online. Please contact the practice.';
        }
        $start = self::startsAt($appointment);
        $notice = HealthSettings::int('health_reschedule_min_notice_hours');
        if (! $start || $start->lte(now()->addHours($notice))) {
            return $notice > 0
                ? "Appointments can be moved online up to {$notice} hour".($notice === 1 ? '' : 's').' before they start. Please contact the practice.'
                : 'This appointment has already started.';
        }

        return null;
    }

    public function canReschedule(Appointment $appointment): bool
    {
        return $this->blockReason($appointment) === null;
    }

    /** Slot grid + free start times for a day, ignoring this appointment's own slot. */
    public function slots(Appointment $appointment, string $date): array
    {
        $appointment->loadMissing('doctor');
        $schedule = $this->booking->slotSchedule($appointment->doctor, (int) $appointment->clinic_id, $date, $appointment->id);
        $slots = $this->booking->availableSlots($appointment->doctor, (int) $appointment->clinic_id, $date, $appointment->id)->values();

        // Don't offer the slot it already occupies.
        if ($appointment->date?->toDateString() === $date) {
            $current = substr((string) $appointment->start_time, 0, 5);
            $slots = $slots->reject(fn ($t) => $t === $current)->values();
        }

        return $schedule + ['slots' => $slots];
    }

    /**
     * @throws \RuntimeException when the slot is gone or the appointment can't move
     */
    public function reschedule(Appointment $appointment, string $date, string $startTime): Appointment
    {
        if ($reason = $this->blockReason($appointment)) {
            throw new \RuntimeException($reason);
        }

        $startTime = substr($startTime, 0, 5);
        $old = $appointment->date->format('D j M').' at '.substr((string) $appointment->start_time, 0, 5);
        if ($appointment->date->toDateString() === $date && substr((string) $appointment->start_time, 0, 5) === $startTime) {
            throw new \RuntimeException('That is already your appointment time.');
        }

        $this->booking->reschedule($appointment, $date, $startTime, ['in_app' => false]);
        $appointment->refresh()->loadMissing(['doctor.user', 'patient.user', 'clinic']);

        $new = $appointment->date->format('D j M').' at '.substr((string) $appointment->start_time, 0, 5);
        $doctorName = $appointment->doctor?->displayName() ?? 'your doctor';
        $patientName = $appointment->patient?->user?->name ?? 'A patient';

        app(InAppNotifier::class)->send(
            $appointment->patient?->user,
            'appointment',
            'Appointment moved',
            "Your appointment with {$doctorName} is now on {$new}.",
            ['route' => 'appointment', 'params' => ['appointment_id' => $appointment->id], 'url' => '/account/appointments']
        );

        if ($appointment->doctor?->user) {
            StaffNotifier::alert(
                $appointment->doctor->user,
                'Appointment rescheduled by patient',
                "{$patientName} moved their appointment from {$old} to {$new}.",
                icon: 'heroicon-o-arrow-path',
                color: 'warning',
                url: route('filament.admin.resources.appointments.index'),
                actionLabel: 'View',
            );
        }

        return $appointment;
    }
}
