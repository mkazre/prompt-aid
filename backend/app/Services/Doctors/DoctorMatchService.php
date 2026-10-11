<?php

namespace App\Services\Doctors;

use App\Contracts\GeocodingInterface;
use App\Contracts\NotificationDispatcherInterface;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\PatientProfile;
use App\Support\StaffNotifier;
use Illuminate\Support\Collection;

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

    /**
     * "Get connected to a Doctor in 30min" (PromptAid Emergency Scale,
     * orange): the request goes to every GP flagged green, and whichever
     * GP accepts first is booked with the patient this many minutes later.
     */
    public const INSTANT_GP_LEAD_MINUTES = 30;

    /** requested_specialty marker for a broadcast-to-all-green-GPs request. */
    public const INSTANT_GP_SPECIALTY = 'General Practitioner';

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

    /** GPs currently flagged green (active, accepting, availability = available). */
    public function greenGps(): Collection
    {
        return DoctorProfile::query()
            ->generalPractitioners()
            ->where('status', 'active')
            ->where('is_accepting_appointments', true)
            ->where('availability', DoctorProfile::AVAILABLE)
            ->with('user')
            ->get();
    }

    /**
     * Broadcast a "see a GP within 30 minutes" request to every green GP.
     * Nobody is auto-assigned: the first GP to accept() it gets the booking.
     */
    public function requestInstantGp(PatientProfile $patient, string $mode, ?string $reason = null): Appointment
    {
        $appointment = $this->requestMatch(
            $patient,
            self::INSTANT_GP_SPECIALTY,
            $mode,
            autoAssign: false,
        );

        if ($reason) {
            $appointment->update(['reason' => $reason]);
        }

        $gps = $this->greenGps();
        $patientName = $patient->user->name;
        $modeLabel = $mode === Appointment::MODE_VIDEO ? 'video' : 'in-person';

        foreach ($gps as $gp) {
            $this->notifier->push(
                $gp->user,
                'Patient needs a GP within 30 min',
                "{$patientName} is asking for a {$modeLabel} consult. First GP to accept gets the booking."
            );
        }

        if ($gps->isNotEmpty()) {
            StaffNotifier::alert(
                $gps->pluck('user')->all(),
                'Patient needs a GP within 30 min',
                "{$patientName} is asking for a {$modeLabel} consult. The first GP to accept gets the booking in 30 minutes.",
                icon: 'heroicon-o-bolt',
                color: 'danger',
                url: route('filament.admin.pages.dashboard'),
                actionLabel: 'Accept on dashboard',
            );
        }

        return $appointment->fresh();
    }

    /** Whether this doctor may accept this open request. */
    public function canAccept(Appointment $appointment, DoctorProfile $doctor): bool
    {
        if ($appointment->requested_specialty === self::INSTANT_GP_SPECIALTY) {
            return $doctor->isGeneralPractitioner();
        }

        return $appointment->requested_specialty === $doctor->specialization;
    }

    /**
     * Atomically claim an open request for a doctor — only the first caller
     * wins, so two GPs tapping Accept at once can never both get the patient.
     * Returns null when someone else already took it.
     */
    public function claim(Appointment $appointment, DoctorProfile $doctor): ?Appointment
    {
        $start = now()->addMinutes(self::INSTANT_GP_LEAD_MINUTES);
        $isInstantGp = $appointment->requested_specialty === self::INSTANT_GP_SPECIALTY;

        $claimed = Appointment::query()
            ->whereKey($appointment->id)
            ->where('status', Appointment::STATUS_MATCHING)
            ->whereNull('doctor_profile_id')
            ->update(array_filter([
                'doctor_profile_id' => $doctor->id,
                // Instant GP requests are slotted 30 minutes from acceptance.
                'date' => $isInstantGp ? $start->toDateString() : null,
                'start_time' => $isInstantGp ? $start->format('H:i:s') : null,
                'end_time' => $isInstantGp ? $start->clone()->addMinutes(self::DEFAULT_DURATION_MINUTES)->format('H:i:s') : null,
                'clinic_id' => $appointment->mode === Appointment::MODE_IN_PERSON ? $doctor->clinics()->value('clinics.id') : null,
            ], fn ($v) => $v !== null));

        if (! $claimed) {
            return null;
        }

        $appointment = $appointment->fresh();

        if ($isInstantGp) {
            $appointment->update(['status' => Appointment::STATUS_CONFIRMED]);
            $doctor->update(['availability' => DoctorProfile::BUSY]);

            $when = $start->format('H:i');
            $this->notifier->push(
                $appointment->patient->user,
                'A GP accepted your request',
                "Dr. {$doctor->user->name} will see you at {$when} ({$appointment->mode}). Booking {$appointment->booking_ref}."
            );

            return $appointment->fresh();
        }

        return $this->assignDoctor($appointment, $doctor);
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
