<?php

namespace App\Services\Commerce;

use App\Models\Appointment;
use App\Models\DoctorDelayNotice;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\Notifications\InAppNotifier;
use App\Support\CommerceSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * "Running late": a doctor tells every patient still waiting today
 * (pending/confirmed, not yet checked in) that they are running X minutes
 * late. Each patient gets an in-app alert (push + web bell) and the send
 * is logged in doctor_delay_notices for admins.
 */
class DoctorDelayService
{
    public const MINUTE_OPTIONS = [15, 30, 45, 60];

    /** @return Collection<int, Appointment> */
    public function remainingToday(DoctorProfile $doctor): Collection
    {
        $today = now(CommerceSettings::timezone())->toDateString();

        return Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->whereDate('date', $today)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->with('patient.user')
            ->orderBy('start_time')
            ->get();
    }

    public function notify(DoctorProfile $doctor, int $minutes, ?string $message, ?User $by, string $source = 'staff'): DoctorDelayNotice
    {
        if (! CommerceSettings::bool('running_late_enabled')) {
            throw new RuntimeException('"Running late" alerts are switched off by the administrator.');
        }
        if ($minutes < 5 || $minutes > 240) {
            throw new RuntimeException('Choose a delay between 5 and 240 minutes.');
        }

        $appointments = $this->remainingToday($doctor);
        if ($appointments->isEmpty()) {
            throw new RuntimeException('You have no remaining patients today to notify.');
        }

        $doctor->loadMissing('user');
        $doctorName = $doctor->user?->name ?? 'Your doctor';
        $message = $message !== null ? trim(mb_substr($message, 0, 500)) : null;
        $notifier = app(InAppNotifier::class);
        $notified = 0;

        foreach ($appointments as $appointment) {
            $user = $appointment->patient?->user;
            if (! $user) {
                continue;
            }

            $start = $appointment->start_time ? substr((string) $appointment->start_time, 0, 5) : null;
            $eta = $start ? Carbon::createFromFormat('H:i', $start)->addMinutes($minutes)->format('H:i') : null;

            $body = $start
                ? "Your appointment at {$start} today may start around {$eta}."
                : 'Your appointment today may start a little later than planned.';
            if ($message) {
                $body .= ' Message from the doctor: "'.$message.'"';
            }

            $sent = $notifier->send(
                $user,
                'appointment',
                "{$doctorName} is running about {$minutes} minutes late",
                $body,
                ['route' => 'appointment', 'params' => ['appointment_id' => $appointment->id], 'url' => '/account/appointments'],
            );
            if ($sent !== null) {
                $notified++;
            }
        }

        return DoctorDelayNotice::query()->create([
            'doctor_profile_id' => $doctor->id,
            'sent_by_user_id' => $by?->id,
            'minutes' => $minutes,
            'message' => $message ?: null,
            'for_date' => now(CommerceSettings::timezone())->toDateString(),
            'patients_notified' => $notified,
            'appointment_ids' => $appointments->pluck('id')->values()->all(),
            'source' => $source,
        ]);
    }
}
