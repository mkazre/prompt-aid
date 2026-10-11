<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Services\Doctors\DoctorMatchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Keeps "See a doctor now" state in step with appointment status changes
 * made anywhere (doctor API, Filament actions, patient cancel, booking
 * service): settles cancellations of matched consults (fee rule if the
 * patient cancelled, free otherwise), closes still-searching requests an
 * admin cancelled, and releases the busy doctor when the consult ends.
 */
class DoctorMatchAppointmentObserver
{
    protected const TERMINAL = [Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW];

    public function updated(Appointment $appointment): void
    {
        if (! $appointment->wasChanged('status') || ! in_array($appointment->status, self::TERMINAL, true)) {
            return;
        }

        try {
            $service = app(DoctorMatchService::class);
            $request = DoctorMatchRequest::query()->where('appointment_id', $appointment->id)->first();

            if ($request && $appointment->status === Appointment::STATUS_CANCELLED) {
                if ($request->status === DoctorMatchRequest::MATCHED) {
                    $byPatient = Auth::id() !== null && Auth::id() === $request->patient?->user_id;
                    $service->settleCancellation($request, $byPatient, (string) ($appointment->cancel_reason ?: 'Cancelled'));
                } elseif ($request->status === DoctorMatchRequest::SEARCHING) {
                    DoctorMatchOffer::query()
                        ->where('doctor_match_request_id', $request->id)
                        ->where('status', DoctorMatchOffer::OFFERED)
                        ->update(['status' => DoctorMatchOffer::EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);
                    $request->update(['status' => DoctorMatchRequest::CANCELLED, 'outcome_reason' => 'cancelled_by_provider', 'cancelled_at' => now()]);
                }
            }

            if ($appointment->doctor_profile_id) {
                $service->releaseForAppointment($appointment);
            }
        } catch (\Throwable $e) {
            // Never block the status change that triggered this.
            Log::warning('[DoctorMatch] observer failed: '.$e->getMessage());
        }
    }
}
