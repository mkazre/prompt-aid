<?php

namespace App\Services\Doctors;

use App\Contracts\GeocodingInterface;
use App\Contracts\NotificationDispatcherInterface;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Support\StaffNotifier;

/**
 * "Uber-style" on-demand doctor matching: request now, get matched to the
 * nearest/soonest available doctor in the requested specialty. Additive to
 * AppointmentBookingService's browse-and-pick-a-slot flow — both create the
 * same Appointment shape, just via a different path to doctor_profile_id.
 */
class DoctorMatchService
{
    // No fixed schedule/slot exists yet for an on-demand request, so the
    // appointment's date/start_time/end_time are stamped to "now" for this
    // many minutes, matching AppointmentBookingService's slot-length fallback.
    protected const DEFAULT_DURATION_MINUTES = 30;

    public function __construct(
        protected GeocodingInterface $geo,
        protected NotificationDispatcherInterface $notifier,
    ) {}

    public function requestMatch(
        PatientProfile $patient,
        string $specialty,
        string $mode,
        ?float $lat = null,
        ?float $lng = null,
        bool $autoAssign = true,
    ): Appointment {
        $now = now();

        $appointment = Appointment::query()->create([
            'patient_profile_id' => $patient->id,
            'doctor_profile_id' => null,
            'clinic_id' => null,
            'date' => $now->toDateString(),
            'start_time' => $now->format('H:i:s'),
            'end_time' => $now->clone()->addMinutes(self::DEFAULT_DURATION_MINUTES)->format('H:i:s'),
            // visit_type predates the mode column; map the closest legacy value
            // so older reports/filters that key off it still bucket sensibly.
            'visit_type' => $mode === Appointment::MODE_VIDEO ? 'telemed' : 'clinic',
            'status' => Appointment::STATUS_MATCHING,
            'mode' => $mode,
            'requested_specialty' => $specialty,
        ]);

        if ($autoAssign) {
            $doctor = $this->findNearestAvailableDoctor($specialty, $mode, $lat, $lng);

            if ($doctor) {
                $this->assignDoctor($appointment, $doctor);
            }
        }

        return $appointment->fresh();
    }

    public function findNearestAvailableDoctor(string $specialty, string $mode, ?float $lat, ?float $lng): ?DoctorProfile
    {
        $query = DoctorProfile::query()
            ->where('specialization', $specialty)
            ->where('status', 'active')
            ->where('is_accepting_appointments', true)
            ->where('availability', DoctorProfile::AVAILABLE);

        if ($mode === Appointment::MODE_IN_PERSON && $lat !== null && $lng !== null) {
            return $query->whereNotNull('current_lat')->whereNotNull('current_lng')
                ->get()
                ->sortBy(fn (DoctorProfile $doctor) => $this->geo->distanceKm($lat, $lng, (float) $doctor->current_lat, (float) $doctor->current_lng))
                ->first();
        }

        // Video mode: proximity is meaningless, so proximity sort is skipped
        // and the doctor who has gone longest without an update wins — a
        // simple fairness tie-break so one doctor doesn't soak up every
        // video request just for being first in the table.
        return $query->orderBy('updated_at')->first();
    }

    public function assignDoctor(Appointment $appointment, DoctorProfile $doctor): Appointment
    {
        $appointment->update([
            'doctor_profile_id' => $doctor->id,
            'status' => Appointment::STATUS_PENDING,
        ]);

        $doctor->update(['availability' => DoctorProfile::BUSY]);

        $this->notifier->push(
            $appointment->patient->user,
            'Doctor matched',
            "Dr. {$doctor->user->name} has been matched to your {$appointment->mode} request and is confirming shortly."
        );
        $this->notifier->push(
            $doctor->user,
            'New consultation request',
            "You've been matched with {$appointment->patient->user->name} for a {$appointment->mode} consultation. Please confirm."
        );

        StaffNotifier::alert(
            $doctor->user,
            'New matched consultation',
            "{$appointment->patient->user->name} was matched to you for a {$appointment->mode} consultation.",
            icon: 'heroicon-o-bolt',
            color: 'warning',
            url: route('filament.admin.resources.appointments.index'),
            actionLabel: 'Review',
        );

        return $appointment->fresh();
    }
}
