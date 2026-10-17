<?php

namespace App\Http\Controllers\Video;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Video\VideoRoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * In-page video calls (Jitsi IFrame API + server-signed JWT):
 *  - call():  patient website page /account/appointments/{id}/call
 *  - embed(): the page the mobile app's WebView loads, authorised by a
 *             single-use ≤5-minute token from GET /api/appointments/{id}/video
 *  - end():   hang-up from either web page (session auth)
 * Doctors use the staff page App\Filament\Pages\ConsultCall.
 */
class VideoCallController extends Controller
{
    public function __construct(protected VideoRoomService $video) {}

    public function call(Request $request, Appointment $appointment)
    {
        $user = $request->user();
        abort_unless($this->video->isParticipant($appointment, $user), 404);

        if ($this->video->isModerator($appointment, $user)) {
            return redirect()->to($this->video->callUrlFor($appointment, $user));
        }
        if (! $appointment->isVideo() || ! $appointment->canJoinVideoNow()) {
            return redirect()->route('account.appointments')->with('error', 'The video room opens 10 minutes before your appointment and closes 30 minutes after it ends.');
        }
        if ($this->video->allowMeetOverride() && $appointment->meet_url) {
            return redirect()->away($appointment->meet_url);
        }

        return $this->render($appointment, $user, $request->boolean('audio'), 'web');
    }

    public function embed(Request $request, int $appointment)
    {
        $claim = $this->video->consumeEmbedToken((string) $request->query('t', ''));
        $appt = $claim ? Appointment::query()->find($appointment) : null;
        $user = $appt && (int) $claim['appointment_id'] === $appt->id ? User::query()->find($claim['user_id']) : null;

        if (! $user || ! $this->video->isParticipant($appt, $user)) {
            return $this->error('expired', 'This call link has expired. Go back and tap Join again.', 410);
        }
        if (! $appt->canJoinVideoNow()) {
            return $this->error('closed', 'The video room opens 10 minutes before the appointment and closes 30 minutes after it ends.', 423);
        }

        return $this->render($appt, $user, (bool) $claim['audio'], 'app');
    }

    public function end(Request $request, Appointment $appointment)
    {
        abort_unless($this->video->isParticipant($appointment, $request->user()), 404);

        $appointment->forceFill(['video_ended_at' => now()])->saveQuietly();
        app(\App\Services\Doctors\DoctorMatchService::class)->releaseForAppointment($appointment);

        return response()->json(['ended_at' => $appointment->video_ended_at?->toIso8601String()]);
    }

    protected function render(Appointment $appointment, User $user, bool $audioOnly, string $mode)
    {
        try {
            $config = $this->video->callConfig(
                $appointment,
                $user,
                $audioOnly,
                $mode,
                $mode === 'app' ? null : route('video.end', $appointment),
                $mode === 'app' ? null : route('account.appointments'),
            );
        } catch (Throwable $e) {
            // Config problem (missing key etc.) — log the reason, never the key.
            Log::warning('Video call config error: '.$e->getMessage(), ['appointment_id' => $appointment->id]);

            return $this->error('config', 'Video calls are temporarily unavailable. Please try again shortly or contact support.', 503);
        }

        return response()
            ->view('video.call', [
                'config' => $config,
                'appointment' => $appointment,
                'messageStartUrl' => $mode === 'web' ? route('account.messages.start') : null,
            ])
            ->withHeaders($this->headers());
    }

    protected function error(string $code, string $message, int $status)
    {
        return response()->view('video.call-error', ['code' => $code, 'message' => $message], $status)->withHeaders($this->headers());
    }

    /** No caching of pages that carry a call token; only send our origin onwards. */
    protected function headers(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'strict-origin',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
