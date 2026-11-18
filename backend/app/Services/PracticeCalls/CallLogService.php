<?php

namespace App\Services\PracticeCalls;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\ConsultSession;
use App\Models\User;
use App\Support\SimplePdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Call records + "proof the consult happened" for video / voice consults in
 * the consult hub (booked on a practice call or any other way):
 *
 *  - consultPresence(): every join / leave of the doctor and the patient is
 *    written to a CallLog (source consult_hub) — first join, last leave,
 *    per-participant events.
 *  - consultEnded(): outcome, who ended, duration (connected seconds from the
 *    consult session).
 *  - evidence(): scheduled vs actual times, participants, segments,
 *    recordings, outcome — for the appointment view, PDF and CSV.
 */
class CallLogService
{
    public function forConsult(Appointment $appt, bool $create = true): ?CallLog
    {
        $log = CallLog::query()->where('appointment_id', $appt->id)->where('source', CallLog::SOURCE_CONSULT)->latest('id')->first();
        if ($log || ! $create) {
            return $log;
        }
        $appt->loadMissing('patient');

        return CallLog::query()->create([
            'kind' => $appt->isVideo() ? 'video' : 'voice',
            'source' => CallLog::SOURCE_CONSULT,
            'status' => CallLog::RINGING,
            'appointment_id' => $appt->id,
            'consult_session_id' => ConsultSession::query()->where('appointment_id', $appt->id)->value('id'),
            'clinic_id' => $appt->clinic_id,
            'doctor_profile_id' => $appt->doctor_profile_id,
            'patient_profile_id' => $appt->patient_profile_id,
            'patient_user_id' => $appt->patient?->user_id,
            'room' => $appt->video_room,
            'ringing_at' => now(),
            'participants' => [],
        ]);
    }

    public function consultPresence(Appointment $appt, User $user, string $channel, bool $in): void
    {
        try {
            $key = 'cl-presence:'.$appt->id.':'.$user->id;
            $state = ($in ? 'in' : 'out').':'.$channel;
            if (\Illuminate\Support\Facades\Cache::get($key) === $state) {
                return;
            }
            \Illuminate\Support\Facades\Cache::put($key, $state, 3600);
            $log = $this->forConsult($appt);
            DB::transaction(function () use ($log, $user, $appt, $channel, $in) {
                $c = CallLog::query()->whereKey($log->id)->lockForUpdate()->first();
                $role = (int) $appt->patient?->user_id === (int) $user->id ? 'patient' : 'doctor';
                $p = (array) $c->participants;
                $p[] = ['user_id' => $user->id, 'role' => $role, 'name' => $role === 'doctor' ? 'Dr. '.$user->name : $user->name, 'event' => $in ? 'joined' : 'left', 'channel' => $channel, 'at' => now()->toIso8601String()];
                $c->participants = array_slice($p, -300);
                $c->kind = $channel === 'video' ? 'video' : ($c->kind ?: 'voice');
                $joined = collect($p)->where('event', 'joined')->pluck('role')->unique();
                if ($c->status === CallLog::RINGING && $joined->contains('doctor') && $joined->contains('patient')) {
                    $c->status = CallLog::CONNECTED;
                    $c->connected_at = now();
                }
                if ($role === 'doctor' && ! $c->practice_user_id) {
                    $c->practice_user_id = $user->id;
                    $c->started_by_user_id ??= $user->id;
                    $c->started_by_role ??= 'doctor';
                }
                $c->save();
            });
        } catch (\Throwable $e) {
            Log::warning('[CallLog] consult presence: '.$e->getMessage());
        }
    }

    public function consultEnded(Appointment $appt, ?User $user, string $outcome): void
    {
        try {
            $log = $this->forConsult($appt, false);
            if (! $log) {
                return;
            }
            $s = ConsultSession::query()->where('appointment_id', $appt->id)->first();
            $log->forceFill([
                'status' => $log->connected_at ? CallLog::ENDED : CallLog::MISSED,
                'ended_at' => $log->ended_at ?? now(),
                'ended_by_user_id' => $user?->id,
                'ended_by_role' => $user ? ((int) $appt->patient?->user_id === (int) $user->id ? 'patient' : 'doctor') : 'system',
                'duration_seconds' => (int) ($s?->connected_seconds ?? ($log->connected_at ? now()->diffInSeconds($log->connected_at, true) : 0)),
                'outcome' => $outcome,
                'consult_session_id' => $s?->id,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('[CallLog] consult ended: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Evidence
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function evidence(Appointment $appt, bool $full = true): array
    {
        $appt->loadMissing(['doctor.user', 'patient.user', 'clinic']);
        $tz = Appointment::CLINIC_TIMEZONE;
        $fmt = fn (?Carbon $t) => $t?->copy()->setTimezone($tz)->format('Y-m-d H:i:s');
        $s = ConsultSession::query()->where('appointment_id', $appt->id)->first();
        $logs = CallLog::query()->with('recordings')->where('appointment_id', $appt->id)->orderBy('id')->get();
        $scheduledStart = $appt->date && $appt->start_time ? Carbon::parse($appt->date->toDateString().' '.substr((string) $appt->start_time, 0, 5), $tz) : null;
        $scheduledEnd = $appt->date && $appt->end_time ? Carbon::parse($appt->date->toDateString().' '.substr((string) $appt->end_time, 0, 5), $tz) : null;

        $segments = [];
        foreach ((array) ($s?->channels ?? []) as $seg) {
            $start = isset($seg['started_at']) ? Carbon::parse($seg['started_at']) : null;
            $end = isset($seg['ended_at']) && $seg['ended_at'] ? Carbon::parse($seg['ended_at']) : (isset($seg['last_at']) ? Carbon::parse($seg['last_at']) : null);
            $segments[] = [
                'channel' => ConsultSession::channelLabel($seg['channel'] ?? null),
                'started_at' => $fmt($start),
                'ended_at' => $fmt($end),
                'seconds' => $start && $end ? max(0, $end->getTimestamp() - $start->getTimestamp()) : (int) ($seg['seconds'] ?? 0),
            ];
        }
        $events = [];
        foreach ($logs as $log) {
            foreach ((array) $log->participants as $ev) {
                $events[] = [
                    'call' => $log->source === CallLog::SOURCE_PRACTICE ? 'Practice call #'.$log->id : 'Consult call #'.$log->id,
                    'name' => (string) ($ev['name'] ?? ''),
                    'role' => (string) ($ev['role'] ?? ''),
                    'event' => (string) ($ev['event'] ?? ''),
                    'channel' => (string) ($ev['channel'] ?? $log->kind),
                    'at' => isset($ev['at']) ? $fmt(Carbon::parse($ev['at'])) : null,
                ];
            }
        }
        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $consultLogs = $logs->where('source', CallLog::SOURCE_CONSULT);
        $firstJoin = collect($events)->where('event', 'joined')->first();
        $actualStart = $s?->connected_at ?? $consultLogs->pluck('connected_at')->filter()->min();
        $actualEnd = $s?->ended_at ?? $consultLogs->pluck('ended_at')->filter()->max();
        $connected = (int) ($s?->connected_seconds ?? $consultLogs->sum('duration_seconds'));
        $participants = collect($events)->groupBy(fn ($e) => $e['role'].'|'.$e['name'])->map(function ($rows) {
            $first = $rows->first();

            return [
                'name' => $first['name'],
                'role' => ucfirst($first['role']),
                'first_joined' => $rows->where('event', 'joined')->min('at'),
                'last_left' => $rows->where('event', 'left')->max('at'),
                'joins' => $rows->where('event', 'joined')->count(),
            ];
        })->values()->all();

        $happened = $connected > 0 || ($actualStart !== null);
        $out = [
            'appointment_id' => $appt->id,
            'booking_ref' => $appt->booking_ref,
            'consult_type' => $appt->isVideo() ? 'Video consult' : ($appt->isCallback() ? 'Call back' : 'In-person consult'),
            'status' => $appt->status,
            'happened' => $happened,
            'doctor' => $appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : null,
            'doctor_hpcsa' => $full ? ($appt->doctor?->registration_no ?: null) : null,
            'patient' => $appt->patient?->user?->name,
            'practice' => $appt->clinic?->name,
            'scheduled_start' => $scheduledStart?->format('Y-m-d H:i'),
            'scheduled_end' => $scheduledEnd?->format('Y-m-d H:i'),
            'actual_start' => $fmt($actualStart ? Carbon::parse($actualStart) : null),
            'actual_end' => $fmt($actualEnd ? Carbon::parse($actualEnd) : null),
            'first_join' => $firstJoin['at'] ?? null,
            'duration_seconds' => $connected,
            'duration_label' => $connected > 0 ? PracticeCallService::fmtDuration($connected) : '—',
            'outcome' => $s?->outcome ? (ConsultSession::OUTCOMES[$s->outcome] ?? $s->outcome) : ($consultLogs->last()?->outcome ? CallLog::outcomeLabel($consultLogs->last()->outcome) : null),
            'ended_by' => $s?->endedBy?->name,
            'timezone' => $tz,
            'generated_at' => now()->setTimezone($tz)->format('Y-m-d H:i:s'),
        ];
        if (! $full) {
            return array_intersect_key($out, array_flip(['appointment_id', 'booking_ref', 'consult_type', 'happened', 'doctor', 'practice', 'scheduled_start', 'actual_start', 'actual_end', 'duration_label', 'duration_seconds']));
        }

        return $out + [
            'participants' => $participants,
            'events' => $events,
            'segments' => $segments,
            'calls' => $logs->map(fn (CallLog $l) => [
                'id' => $l->id, 'source' => $l->source, 'kind' => $l->kind, 'status' => $l->status, 'outcome' => CallLog::outcomeLabel($l->outcome),
                'ringing_at' => $fmt($l->ringing_at), 'connected_at' => $fmt($l->connected_at), 'ended_at' => $fmt($l->ended_at), 'duration' => PracticeCallService::fmtDuration((int) $l->duration_seconds),
                'recordings' => $l->recordings->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'disk' => $r->disk, 'sha256' => $r->sha256, 'bytes' => (int) $r->size_bytes])->values()->all(),
            ])->values()->all(),
        ];
    }

    public function evidencePdf(Appointment $appt): string
    {
        $e = $this->evidence($appt);
        $pdf = new SimplePdf;
        $pdf->addPage();
        $w = SimplePdf::W - 2 * SimplePdf::MARGIN;
        // SimplePdf::row() takes [[text, width, align], ...]: split the line evenly (2 columns = 35/65).
        $row = function (array $texts, float $size = 9) use ($pdf, $w) {
            $n = count($texts);
            $widths = $n === 2 ? [$w * 0.35, $w * 0.65] : array_fill(0, $n, $w / max(1, $n));
            $pdf->row(array_map(fn ($t, $wd) => [(string) $t, $wd, 'left'], $texts, $widths), $size);
        };
        $pdf->line('Prompt Aid — Consultation evidence record', 15, true, 8);
        $pdf->line('Generated '.$e['generated_at'].' ('.$e['timezone'].')', 8);
        $pdf->rule();
        foreach ([
            'Booking ref' => $e['booking_ref'], 'Consult type' => $e['consult_type'], 'Doctor' => $e['doctor'], 'Patient' => $e['patient'], 'Practice' => $e['practice'],
            'Scheduled' => trim(($e['scheduled_start'] ?? '').' – '.substr((string) ($e['scheduled_end'] ?? ''), 11)), 'Actual start' => $e['actual_start'], 'Actual end' => $e['actual_end'],
            'Connected time' => $e['duration_label'], 'Outcome' => $e['outcome'], 'Consult took place' => $e['happened'] ? 'Yes — both parties connected' : 'No connection recorded',
        ] as $k => $v) {
            $row([$k, (string) ($v ?? '—')]);
        }
        $pdf->space();
        $pdf->line('Participants', 11, true);
        foreach ($e['participants'] as $p) {
            $row([$p['role'].': '.$p['name'], 'first joined '.($p['first_joined'] ?? '—'), 'last left '.($p['last_left'] ?? '—'), $p['joins'].' join(s)'], 8);
        }
        $pdf->space();
        $pdf->line('Channel segments', 11, true);
        foreach ($e['segments'] ?: [['channel' => '—', 'started_at' => '', 'ended_at' => '', 'seconds' => 0]] as $sg) {
            $row([$sg['channel'], (string) $sg['started_at'], (string) $sg['ended_at'], PracticeCallService::fmtDuration((int) $sg['seconds'])], 8);
        }
        $pdf->space();
        $pdf->line('Event log', 11, true);
        foreach (array_slice($e['events'], 0, 120) as $ev) {
            $row([(string) $ev['at'], $ev['call'], ucfirst($ev['role']).' '.$ev['name'], $ev['event'].' ('.$ev['channel'].')'], 8);
        }
        $pdf->space();
        $pdf->line('Recordings', 11, true);
        foreach ($e['calls'] as $c) {
            foreach ($c['recordings'] as $r) {
                $row(['Call #'.$c['id'], $r['status'].' · '.($r['disk'] ?? '—'), 'sha256 '.substr((string) $r['sha256'], 0, 32).'…'], 8);
            }
        }

        return $pdf->output();
    }

    public function evidenceCsv(Appointment $appt): string
    {
        $e = $this->evidence($appt);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['section', 'field', 'value', 'extra1', 'extra2']);
        foreach (['booking_ref', 'consult_type', 'doctor', 'patient', 'practice', 'scheduled_start', 'scheduled_end', 'actual_start', 'actual_end', 'duration_seconds', 'outcome', 'happened', 'timezone', 'generated_at'] as $k) {
            fputcsv($fh, ['summary', $k, is_bool($e[$k]) ? ($e[$k] ? 'yes' : 'no') : (string) ($e[$k] ?? ''), '', '']);
        }
        foreach ($e['participants'] as $p) {
            fputcsv($fh, ['participant', $p['role'], $p['name'], (string) $p['first_joined'], (string) $p['last_left']]);
        }
        foreach ($e['segments'] as $sg) {
            fputcsv($fh, ['segment', $sg['channel'], (string) $sg['seconds'], (string) $sg['started_at'], (string) $sg['ended_at']]);
        }
        foreach ($e['events'] as $ev) {
            fputcsv($fh, ['event', (string) $ev['at'], $ev['role'].' '.$ev['name'], $ev['event'], $ev['call']]);
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** Admin / practice / doctor of the appointment (full); its patient (summary). */
    public function evidenceAccess(Appointment $appt, ?User $user): ?string
    {
        if (! $user || $user->status !== 'active') {
            return null;
        }
        if ($user->isSuperAdmin()) {
            return 'full';
        }
        $appt->loadMissing('patient');
        if ($user->isPatient() && (int) $appt->patient?->user_id === (int) $user->id) {
            return 'summary';
        }
        if ($user->isDoctor() && $user->doctorProfile && (int) $appt->doctor_profile_id === (int) $user->doctorProfile->id) {
            return 'full';
        }
        if ($appt->clinic_id && in_array((int) $appt->clinic_id, $user->practiceClinicIds(), true) && ($user->isClinicAdmin() || $user->isPracticeReception())) {
            return 'full';
        }

        return null;
    }
}
