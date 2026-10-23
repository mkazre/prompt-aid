<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Message;
use App\Services\Comms\ChatService;
use App\Services\Consults\ConsultCallService;
use App\Services\Consults\ConsultHubService;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Video\VideoRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consult hub endpoints — shared by the app API (/api/consult-hub/*,
 * Sanctum) and the website / staff panel (/account/consult-hub/*, session).
 * Everything 404s for anyone but the appointment's own patient / doctor
 * (admins included). Doctor-only actions answer 403 to the patient.
 */
class ConsultHubController extends Controller
{
    public function __construct(protected ConsultHubService $hub) {}

    // ------------------------------------------------------------ patient web page

    /** /account/consults/{appointment} — the patient's hub page (website). */
    public function page(Request $request, int $appointment)
    {
        $user = $request->user();
        try {
            $appt = ConsultHubService::find($appointment);
            $role = $this->hub->authorize($appt, $user);
            if ($role === 'doctor') {
                return redirect()->to(ConsultHubService::doctorHubUrl($appt) ?? '/staff');
            }
            $this->hub->open($appt, $user);
            $payload = $this->hub->payload($appt, $user);
        } catch (DoctorMatchException $e) {
            abort($e->status === 422 ? 404 : $e->status, $e->getMessage());
        }

        $join = in_array($request->query('join'), ['video', 'voice'], true) ? $request->query('join') : null;
        $callConfig = $this->callConfig($appt, $user, $join === 'voice', 'web');
        if ($join && $callConfig) {
            // Arriving from "Dr X is calling you": stop the ring.
            try {
                app(ConsultCallService::class)->answer($appt, $user);
            } catch (Throwable) {
            }
        }

        return response()
            ->view('account.consult-hub', [
                'appointment' => $appt,
                'hub' => $payload,
                'hubConfig' => $this->webConfig($appt, $payload, $callConfig, $join, 'patient'),
                'callConfig' => $callConfig,
            ])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'strict-origin']);
    }

    // ------------------------------------------------------------ JSON

    public function show(Request $request, int $appointment): JsonResponse
    {
        return $this->guard(function () use ($request, $appointment) {
            $appt = ConsultHubService::find($appointment);
            $this->hub->open($appt, $request->user());

            return ['data' => $this->hub->payload($appt, $request->user())];
        });
    }

    public function presence(Request $request, int $appointment): JsonResponse
    {
        $v = $request->validate([
            'channel' => ['required', 'in:video,voice'],
            'state' => ['nullable', 'in:in,out'],
        ]);

        return $this->guard(function () use ($request, $appointment, $v) {
            $appt = ConsultHubService::find($appointment);
            $this->hub->presence($appt, $request->user(), $v['channel'], ($v['state'] ?? 'in') === 'in');

            return ['data' => $this->hub->payload($appt->fresh(['patient.user', 'doctor.user']), $request->user())];
        });
    }

    public function start(Request $request, int $appointment): JsonResponse
    {
        $channel = $request->validate(['channel' => ['nullable', 'in:video,voice']])['channel'] ?? 'video';

        return $this->guard(function () use ($request, $appointment, $channel) {
            $appt = $this->hub->startCall(ConsultHubService::find($appointment), $request->user(), $channel);

            return ['data' => $this->hub->payload($appt, $request->user()), 'rang' => $channel];
        });
    }

    public function calling(Request $request, int $appointment): JsonResponse
    {
        $via = $request->validate(['via' => ['required', 'in:phone,whatsapp']])['via'];

        return $this->guard(function () use ($request, $appointment, $via) {
            $appt = $this->hub->calling(ConsultHubService::find($appointment), $request->user(), $via);

            return ['data' => $this->hub->payload($appt->fresh(['patient.user', 'doctor.user']), $request->user())];
        });
    }

    public function connected(Request $request, int $appointment): JsonResponse
    {
        $via = $request->validate(['via' => ['required', 'in:phone,whatsapp']])['via'];

        return $this->guard(function () use ($request, $appointment, $via) {
            $appt = ConsultHubService::find($appointment);
            $this->hub->phoneConnected($appt, $request->user(), $via);

            return ['data' => $this->hub->payload($appt->fresh(['patient.user', 'doctor.user']), $request->user())];
        });
    }

    public function end(Request $request, int $appointment): JsonResponse
    {
        $v = $request->validate([
            'outcome' => ['required', 'in:'.implode(',', array_keys(\App\Models\ConsultSession::OUTCOMES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->guard(function () use ($request, $appointment, $v) {
            $appt = ConsultHubService::find($appointment);
            $this->hub->end($appt, $request->user(), $v['outcome'], $v['notes'] ?? null);

            return ['data' => $this->hub->payload($appt->fresh(['patient.user', 'doctor.user']), $request->user())];
        });
    }

    public function requestCallback(Request $request, int $appointment): JsonResponse
    {
        $v = $request->validate([
            'via' => ['required', 'in:phone,whatsapp'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->guard(function () use ($request, $appointment, $v) {
            $appt = $this->hub->requestCallback(ConsultHubService::find($appointment), $request->user(), $v['via'], $v['phone'] ?? null);

            return ['data' => $this->hub->payload($appt, $request->user())];
        });
    }

    public function chat(Request $request, int $appointment): JsonResponse
    {
        return $this->guard(function () use ($request, $appointment) {
            $user = $request->user();
            $appt = ConsultHubService::find($appointment);
            $this->hub->authorize($appt, $user);
            $chat = app(ChatService::class);
            $conversation = $chat->forAppointment($appt, $user);
            if (! $conversation) {
                throw new DoctorMatchException('Chat is not available for this consult.', 404);
            }
            $messages = $chat->messages($conversation, $request->integer('after') ?: null);
            if ($messages->isNotEmpty()) {
                $chat->markRead($conversation, $user);
            }

            return [
                'conversation_id' => $conversation->id,
                'data' => $messages->map(fn (Message $m) => $m->toChatArray($user))->values(),
            ];
        });
    }

    public function sendChat(Request $request, int $appointment): JsonResponse
    {
        $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachment'],
            'attachment' => ['nullable', 'file', 'mimes:'.ChatService::ATTACHMENT_MIMES, 'max:'.ChatService::ATTACHMENT_MAX_KB],
        ]);

        return $this->guard(function () use ($request, $appointment) {
            $user = $request->user();
            $appt = ConsultHubService::find($appointment);
            $this->hub->authorize($appt, $user);
            $chat = app(ChatService::class);
            $conversation = $chat->forAppointment($appt, $user);
            if (! $conversation) {
                throw new DoctorMatchException('Chat is not available for this consult.', 404);
            }
            $message = $chat->send($conversation, $user, $request->input('body'), $request->file('attachment'));

            return ['data' => $message->toChatArray($user)];
        }, 201);
    }

    // ------------------------------------------------------------ helpers (also used by the Filament page)

    /** Jitsi config for the in-page call, or null when the room isn't open / not configured. */
    public function callConfig(Appointment $appt, $user, bool $audioOnly, string $mode): ?array
    {
        if (! $appt->isVideo() || ! $appt->canJoinVideoNow() || ! $this->hub->isOpen($appt)) {
            return null;
        }
        $video = app(VideoRoomService::class);
        if ($video->allowMeetOverride() && $appt->meet_url) {
            return null;
        }
        try {
            $cfg = $video->callConfig($appt, $user, $audioOnly, $mode, null, null);
            $isDoctor = $video->isModerator($appt, $user);
            $appt->loadMissing(['doctor.user', 'patient.user']);
            $s = $this->hub->existingSession($appt);

            // The hub page owns start / channel / rejoin; the stage shows the consult timer.
            return $cfg + [
                'hub' => true,
                'autostart' => false,
                'counterpartAvatar' => \App\Support\Widgets\WidgetDataService::mediaUrl($isDoctor ? $appt->patient?->user?->avatar : $appt->doctor?->user?->avatar),
                'timerFrom' => $s?->connected_at?->toIso8601String(),
                'serverTime' => now()->toIso8601String(),
                'waitingText' => $isDoctor ? 'Start a video or voice call — the patient\'s phone will ring.' : 'Tap Video call or Voice call to join. Your doctor will ring you when they are ready.',
            ];
        } catch (Throwable $e) {
            Log::warning('Consult hub call config error: '.$e->getMessage(), ['appointment_id' => $appt->id]);

            return null;
        }
    }

    /** JSON-safe config for public/assets/js/pa-consult-hub.js (precomputed for @json). */
    public function webConfig(Appointment $appt, array $payload, ?array $callConfig, ?string $join, string $role): array
    {
        $base = url('/account/consult-hub/'.$appt->id);
        $video = app(VideoRoomService::class);

        return [
            'appointmentId' => $appt->id,
            'role' => $role,
            'stateUrl' => $base.'/state',
            'presenceUrl' => $base.'/presence',
            'startUrl' => $base.'/start',
            'callingUrl' => $base.'/calling',
            'connectedUrl' => $base.'/connected',
            'endUrl' => $base.'/end',
            'callbackUrl' => $base.'/request-callback',
            'chatUrl' => $base.'/chat',
            'answerUrl' => url('/account/consults/'.$appt->id.'/ring/answer'),
            'declineUrl' => url('/account/consults/'.$appt->id.'/ring/decline'),
            'csrf' => csrf_token(),
            'join' => $join,
            'autoJoin' => $role === 'patient' && $callConfig !== null && ($join !== null || ($payload['ringing'] ?? null) !== null),
            'callReady' => $callConfig !== null,
            'meetUrl' => $video->allowMeetOverride() && $appt->meet_url ? $appt->meet_url : null,
            'initial' => $payload,
            'maxUploadKb' => ChatService::ATTACHMENT_MAX_KB,
        ];
    }

    protected function guard(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status)->header('Cache-Control', 'no-store, private');
        } catch (DoctorMatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
