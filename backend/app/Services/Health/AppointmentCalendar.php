<?php

namespace App\Services\Health;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds an RFC 5545 iCalendar file for one appointment, with a display
 * alarm one hour before it starts. Times are written in UTC, converted
 * from the appointment's local (Africa/Johannesburg) date + start time.
 */
class AppointmentCalendar
{
    public function ics(Appointment $appointment): string
    {
        $appointment->loadMissing(['doctor.user', 'clinic', 'service']);

        $start = PatientRescheduleService::startsAt($appointment) ?? now();
        $end = $appointment->end_time
            ? Carbon::parse($appointment->date->toDateString().' '.substr((string) $appointment->end_time, 0, 5), $start->getTimezone())
            : $start->copy()->addMinutes(30);
        if ($end->lte($start)) {
            $end = $start->copy()->addMinutes(30);
        }

        $doctor = $appointment->doctor?->displayName() ?? 'your doctor';
        $summary = ($appointment->isVideo() ? 'Video consult with ' : 'Appointment with ').$doctor;

        $location = $appointment->isVideo()
            ? 'Video consult (join from your Prompt Aid account)'
            : collect([$appointment->clinic?->name, $appointment->clinic?->address, $appointment->clinic?->city])->filter()->implode(', ');

        $description = collect([
            $appointment->service?->name ? 'Service: '.$appointment->service->name : null,
            'Booking ref: '.$appointment->booking_ref,
            $appointment->clinic?->phone ? 'Practice phone: '.$appointment->clinic->phone : null,
            'Manage: '.route('account.appointments'),
        ])->filter()->implode("\n");

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'promptaid.co.za';
        $utc = fn (Carbon $c) => $c->copy()->setTimezone('UTC')->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Prompt Aid//Appointments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:appointment-'.$appointment->id.'@'.$host,
            'DTSTAMP:'.$utc(now()),
            'DTSTART:'.$utc($start),
            'DTEND:'.$utc($end),
            'SUMMARY:'.$this->escape($summary),
            'DESCRIPTION:'.$this->escape($description),
            'LOCATION:'.$this->escape($location ?: 'Prompt Aid'),
            'STATUS:'.($appointment->status === Appointment::STATUS_CONFIRMED ? 'CONFIRMED' : 'TENTATIVE'),
            // Grows when the appointment changes, so re-importing updates the event.
            'SEQUENCE:'.(int) ($appointment->updated_at?->timestamp ?? 0),
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'DESCRIPTION:'.$this->escape($summary.' in 1 hour'),
            'TRIGGER:-PT1H',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(fn ($l) => $this->fold($l), $lines))."\r\n";
    }

    public function download(Appointment $appointment): Response
    {
        $name = 'appointment-'.($appointment->booking_ref ?: $appointment->id).'.ics';

        return response($this->ics($appointment), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Short-lived signed URL the app can open without a session or token. */
    public function signedUrl(Appointment $appointment, int $minutes = 30): string
    {
        return URL::temporarySignedRoute('api.health.appointments.ics', now()->addMinutes($minutes), ['appointment' => $appointment->id]);
    }

    protected function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
    }

    /** Fold content lines longer than 75 octets (RFC 5545 §3.1). */
    protected function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out ? 74 : 75;
            if (strlen($current.$char) > $limit) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
