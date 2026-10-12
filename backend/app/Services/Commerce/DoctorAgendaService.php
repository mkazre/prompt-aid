<?php

namespace App\Services\Commerce;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Support\CommerceSettings;
use App\Support\StaffNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Morning agenda alert: at the admin-set time (default 07:00
 * Africa/Johannesburg) each doctor with appointments today gets one staff
 * alert ("You have N appointments today, first at 08:30") linking to their
 * appointments. Runs from Tick every few minutes; a per-doctor-per-day
 * cache marker makes it send at most once.
 */
class DoctorAgendaService
{
    public const ACTIVE_STATUSES = [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN];

    /** @return int number of doctors alerted */
    public function run(): int
    {
        if (! CommerceSettings::bool('doctor_agenda_enabled')) {
            return 0;
        }

        $tz = CommerceSettings::timezone();
        $now = now($tz);
        $time = CommerceSettings::str('doctor_agenda_time');
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            $time = '07:00';
        }
        [$h, $m] = array_map('intval', explode(':', $time));
        if ($now->lt($now->copy()->setTime($h, $m))) {
            return 0;
        }

        $date = $now->toDateString();

        $rows = Appointment::query()
            ->whereNotNull('doctor_profile_id')
            ->whereDate('date', $date)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->selectRaw('doctor_profile_id, COUNT(*) as total, MIN(start_time) as first_start')
            ->groupBy('doctor_profile_id')
            ->get();

        $sent = 0;
        foreach ($rows as $row) {
            $key = "doctor_agenda:{$row->doctor_profile_id}:{$date}";
            if (Cache::has($key)) {
                continue;
            }

            $doctor = DoctorProfile::query()->with('user')->find($row->doctor_profile_id);
            $user = $doctor?->user;
            if (! $user || $user->status !== 'active') {
                continue;
            }

            if (! Cache::add($key, now()->toIso8601String(), 60 * 60 * 48)) {
                continue;
            }

            try {
                $count = (int) $row->total;
                $first = $row->first_start ? substr((string) $row->first_start, 0, 5) : null;

                StaffNotifier::alert(
                    $user,
                    "You have {$count} ".($count === 1 ? 'appointment' : 'appointments').' today',
                    $first ? "Your first appointment is at {$first}." : 'Open your appointments to see the day.',
                    icon: 'heroicon-o-calendar-days',
                    color: 'info',
                    url: route('filament.admin.resources.appointments.index'),
                    actionLabel: 'Open appointments',
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[DoctorAgenda] alert failed for doctor '.$row->doctor_profile_id.': '.$e->getMessage());
            }
        }

        return $sent;
    }
}
