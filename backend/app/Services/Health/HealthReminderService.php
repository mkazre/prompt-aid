<?php

namespace App\Services\Health;

use App\Models\Appointment;
use App\Models\Medication;
use App\Services\Notifications\InAppNotifier;
use App\Support\HealthSettings;
use App\Support\StaffNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The two Tick jobs of the health stream:
 *  - medication dose reminders (every 60s, Africa/Johannesburg time, one
 *    alert per medication × day × time via the medication_doses log);
 *  - appointment reminders (first + second lead time, to the patient
 *    in-app and the doctor via StaffNotifier, marked on the appointment).
 * Both are idempotent: the marker is written before the alert is sent, by
 * a conditional insert/update, so overlapping runs can't double-send.
 */
class HealthReminderService
{
    public function __construct(protected InAppNotifier $inApp) {}

    /** @return int alerts sent */
    public function sendMedicationReminders(): int
    {
        if (! HealthSettings::feature('medications') || ! HealthSettings::bool('health_medication_reminders_enabled')) {
            return 0;
        }

        $now = now(HealthSettings::TIMEZONE);
        $day = $now->toDateString();
        $nowMinutes = $now->hour * 60 + $now->minute;
        // Catch-up window: a dose missed by a slow tick still gets one alert
        // until it would be counted as missed.
        $window = HealthSettings::int('health_medication_missed_after_minutes') ?: 60;
        $title = HealthSettings::get('health_medication_reminder_title');
        $template = HealthSettings::get('health_medication_reminder_body');
        $sent = 0;

        Medication::query()
            ->activeOn($day)
            ->where('reminders_enabled', true)
            ->with('patient.user')
            ->chunkById(200, function ($medications) use ($day, $nowMinutes, $window, $title, $template, &$sent) {
                foreach ($medications as $medication) {
                    $user = $medication->patient?->user;
                    if (! $user) {
                        continue;
                    }
                    foreach ($medication->doseTimes() as $time) {
                        [$h, $m] = array_map('intval', explode(':', $time));
                        $mins = $h * 60 + $m;
                        if ($mins > $nowMinutes || $nowMinutes - $mins > $window) {
                            continue;
                        }

                        $inserted = DB::table('medication_doses')->insertOrIgnore([
                            'medication_id' => $medication->id,
                            'dose_date' => $day,
                            'dose_time' => $time,
                            'reminder_sent_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        if (! $inserted) {
                            continue; // already reminded (or already marked taken)
                        }

                        $this->inApp->send(
                            $user,
                            'medication',
                            $title,
                            HealthSettings::fill($template, ['dose' => $medication->dose, 'name' => $medication->name, 'time' => $time]),
                            ['route' => 'medications', 'params' => ['medication_id' => $medication->id], 'url' => '/account/medications']
                        );
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /** @return int appointments reminded */
    public function sendAppointmentReminders(): int
    {
        if (! HealthSettings::bool('health_appt_reminders_enabled')) {
            return 0;
        }

        $firstLead = max(1, HealthSettings::int('health_appt_reminder_first_hours')) * 60;
        $secondLead = max(1, HealthSettings::int('health_appt_reminder_second_minutes'));
        if ($secondLead >= $firstLead) {
            $secondLead = (int) floor($firstLead / 2);
        }

        $nowLocal = now(HealthSettings::TIMEZONE);
        $until = $nowLocal->copy()->addMinutes($firstLead)->addDay();
        $count = 0;

        $appointments = Appointment::query()
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->whereNotNull('doctor_profile_id')
            ->whereDate('date', '>=', $nowLocal->toDateString())
            ->whereDate('date', '<=', $until->toDateString())
            ->where(fn ($q) => $q->whereNull('reminder_24h_sent_at')->orWhereNull('reminder_1h_sent_at'))
            ->with(['patient.user', 'doctor.user', 'clinic'])
            ->orderBy('date')->orderBy('start_time')
            ->limit(500)
            ->get();

        foreach ($appointments as $appointment) {
            $start = PatientRescheduleService::startsAt($appointment);
            if (! $start) {
                continue;
            }
            $minutesUntil = (int) floor(($start->getTimestamp() - time()) / 60);
            if ($minutesUntil <= 0) {
                continue;
            }

            try {
                if (! $appointment->reminder_1h_sent_at && $minutesUntil <= $secondLead) {
                    $claimed = Appointment::query()->whereKey($appointment->id)->whereNull('reminder_1h_sent_at')->toBase()->update([
                        'reminder_1h_sent_at' => now(),
                        // The first reminder would be redundant now.
                        'reminder_24h_sent_at' => DB::raw('COALESCE(reminder_24h_sent_at, CURRENT_TIMESTAMP)'),
                    ]);
                    if ($claimed) {
                        $this->remind($appointment, $start);
                        $count++;
                    }
                } elseif (! $appointment->reminder_24h_sent_at && $minutesUntil <= $firstLead && $minutesUntil > $secondLead) {
                    $claimed = Appointment::query()->whereKey($appointment->id)->whereNull('reminder_24h_sent_at')->toBase()->update(['reminder_24h_sent_at' => now()]);
                    if ($claimed) {
                        $this->remind($appointment, $start);
                        $count++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[HealthReminders] appointment '.$appointment->id.': '.$e->getMessage());
            }
        }

        return $count;
    }

    protected function remind(Appointment $appointment, \Carbon\Carbon $start): void
    {
        $place = $appointment->isVideo() ? ' (video consult)' : ($appointment->clinic ? ' at '.$appointment->clinic->name : '');
        $vars = [
            'doctor' => $appointment->doctor?->displayName() ?? 'your doctor',
            'patient' => $appointment->patient?->user?->name ?? 'A patient',
            'date' => $start->isToday() ? 'today' : ($start->isTomorrow() ? 'tomorrow' : 'on '.$start->format('D j M')),
            'time' => $start->format('H:i'),
            'place' => $place,
        ];
        $title = HealthSettings::get('health_appt_reminder_title');

        $this->inApp->send(
            $appointment->patient?->user,
            'appointment',
            $title,
            HealthSettings::fill(HealthSettings::get('health_appt_reminder_patient_body'), $vars),
            ['route' => 'appointment', 'params' => ['appointment_id' => $appointment->id], 'url' => '/account/appointments']
        );

        if ($appointment->doctor?->user) {
            StaffNotifier::alert(
                $appointment->doctor->user,
                $title,
                HealthSettings::fill(HealthSettings::get('health_appt_reminder_doctor_body'), $vars),
                icon: 'heroicon-o-clock',
                color: 'info',
                url: route('filament.admin.resources.appointments.index'),
                actionLabel: 'View',
            );
        }
    }
}
