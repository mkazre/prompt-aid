<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Video\VideoRoomService;
use Illuminate\Http\Request;

/**
 * Join link for an appointment's in-app video room — only for the
 * appointment's own patient or doctor, and only inside the join window
 * (10 min before start → 30 min after end).
 */
class VideoConsultController extends Controller
{
    public function __construct(protected VideoRoomService $video) {}

    public function show(Request $request, int $appointment)
    {
        $user = $request->user();
        $appt = Appointment::query()->with(['doctor.user', 'patient.user'])->find($appointment);

        // 404 (not 403) for strangers so appointment ids can't be probed.
        abort_unless($appt && $this->video->isParticipant($appt, $user), 404);

        $base = [
            'appointment_id' => $appt->id,
            'window_opens_at' => $appt->videoWindowStart()?->toIso8601String(),
            'window_closes_at' => $appt->videoWindowEnd()?->toIso8601String(),
            'counterpart_name' => $user->isDoctor() ? $appt->patient?->user?->name : ($appt->doctor?->user ? 'Dr. '.$appt->doctor->user->name : null),
        ];

        if (! $appt->isVideo()) {
            return response()->json(array_merge($base, ['message' => 'This is not a video appointment.']), 422);
        }

        if (! $appt->canJoinVideoNow()) {
            return response()->json(array_merge($base, [
                'message' => 'The video room opens 10 minutes before the appointment and closes 30 minutes after it ends.',
            ]), 423);
        }

        // {provider, domain, room, jwt, embed_url (single-use, 5 min), expires_at, role}
        // plus the legacy join_url/external/display_name for older app builds.
        try {
            $payload = $this->video->joinPayload($appt, $user, $request->boolean('audio'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Video join config error: '.$e->getMessage(), ['appointment_id' => $appt->id]);

            return response()->json(array_merge($base, ['message' => 'Video calls are temporarily unavailable. Please try again shortly.']), 503);
        }

        // Patient joining stops the ring; the doctor opening the call (app "Start") rings the patient.
        try {
            $calls = app(\App\Services\Consults\ConsultCallService::class);
            if ($this->video->isModerator($appt, $user)) {
                $calls->ring($appt);
            } else {
                $calls->answer($appt, $user);
            }
        } catch (\Throwable) {
        }

        return response()->json(array_merge($base, $payload))->header('Cache-Control', 'no-store, private');
    }

    /** Hang-up: records that the call ended (doesn't change the appointment status). */
    public function end(Request $request, int $appointment)
    {
        $appt = Appointment::query()->find($appointment);
        abort_unless($appt && $this->video->isParticipant($appt, $request->user()), 404);

        $appt->forceFill(['video_ended_at' => now()])->saveQuietly();

        // An on-demand ("See a doctor now") doctor is free again once the call ends.
        app(\App\Services\Doctors\DoctorMatchService::class)->releaseForAppointment($appt);

        return response()->json(['ended_at' => $appt->video_ended_at?->toIso8601String()]);
    }
}
