<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Comms\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Doctor–patient chat for the mobile app. Every endpoint scopes through
 * ConversationPolicy; listing only ever returns the caller's own threads.
 */
class ConversationController extends Controller
{
    public function __construct(protected ChatService $chat) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->forParticipant($user)
            ->with(['patient.user:id,name,avatar', 'doctor.user:id,name,avatar', 'latestMessage', 'appointment'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where('sender_user_id', '!=', $user->id)])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => $this->present($c, $user))->values(),
            'total_unread' => (int) $conversations->sum('unread_count'),
        ]);
    }

    /**
     * Start (or fetch) the thread. Body: { appointment_id } — or
     * { doctor_profile_id } as a patient / { patient_profile_id } as a doctor.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        Gate::authorize('create', Conversation::class);

        $data = $request->validate([
            'appointment_id' => ['nullable', 'integer'],
            'doctor_profile_id' => ['nullable', 'integer'],
            'patient_profile_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['appointment_id'])) {
            $appointment = Appointment::query()->find($data['appointment_id']);
            $conversation = $appointment ? $this->chat->forAppointment($appointment, $user) : null;
        } else {
            $conversation = $this->chat->forCounterpart($user, $data['doctor_profile_id'] ?? null, $data['patient_profile_id'] ?? null);
        }

        if (! $conversation) {
            return response()->json(['message' => 'You can only message a doctor or patient you have an appointment with.'], 403);
        }

        return response()->json(['data' => $this->present($conversation->load(['patient.user', 'doctor.user', 'latestMessage', 'appointment']), $user)]);
    }

    public function show(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        return response()->json(['data' => $this->present($conversation->load(['patient.user', 'doctor.user', 'latestMessage', 'appointment']), $request->user())]);
    }

    /** ?after=<id> for polling (newer only), ?before=<id> for scroll-back. */
    public function messages(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $user = $request->user();

        $messages = $this->chat->messages(
            $conversation,
            $request->integer('after') ?: null,
            $request->integer('before') ?: null,
        );

        // Reading the thread as a participant marks the other side's messages read.
        if ($conversation->isParticipant($user) && $messages->isNotEmpty()) {
            $this->chat->markRead($conversation, $user);
        }

        // Read receipts for my own messages can change without new ids, so
        // the poll also returns the newest id of mine the other side has read.
        $lastReadMine = $conversation->messages()
            ->where('sender_user_id', $user->id)
            ->whereNotNull('read_at')
            ->max('id');

        return response()->json([
            'data' => $messages->map(fn (Message $m) => $m->toChatArray($user))->values(),
            'last_read_own_id' => $lastReadMine ? (int) $lastReadMine : null,
        ]);
    }

    public function send(Request $request, Conversation $conversation)
    {
        Gate::authorize('participate', $conversation);

        $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachment'],
            'attachment' => ['nullable', 'file', 'mimes:'.ChatService::ATTACHMENT_MIMES, 'max:'.ChatService::ATTACHMENT_MAX_KB],
        ]);

        $message = $this->chat->send($conversation, $request->user(), $request->input('body'), $request->file('attachment'));

        return response()->json(['data' => $message->toChatArray($request->user())], 201);
    }

    public function markRead(Request $request, Conversation $conversation)
    {
        Gate::authorize('participate', $conversation);

        return response()->json(['updated' => $this->chat->markRead($conversation, $request->user())]);
    }

    /**
     * Private-disk download behind a short-lived relative signature (see
     * Message::attachmentUrl) — URLs are only ever minted for callers who
     * already passed ConversationPolicy::view.
     */
    public function attachment(Message $message)
    {
        abort_unless($message->attachment_path && Storage::disk(ChatService::ATTACHMENT_DISK)->exists($message->attachment_path), 404);

        $disposition = $message->isImage() || $message->attachment_mime === 'application/pdf' ? 'inline' : 'attachment';

        return Storage::disk(ChatService::ATTACHMENT_DISK)->response(
            $message->attachment_path,
            $message->attachment_name,
            ['Content-Type' => $message->attachment_mime ?: 'application/octet-stream', 'Cache-Control' => 'private, max-age=3600'],
            $disposition
        );
    }

    protected function present(Conversation $c, User $viewer): array
    {
        $isDoctorView = $viewer->isDoctor() && $viewer->doctorProfile?->id === $c->doctor_profile_id;
        $counterpart = $isDoctorView ? $c->patient?->user : $c->doctor?->user;

        return [
            'id' => $c->id,
            'patient_profile_id' => $c->patient_profile_id,
            'doctor_profile_id' => $c->doctor_profile_id,
            'counterpart' => [
                'name' => $counterpart ? ($isDoctorView ? $counterpart->name : 'Dr. '.$counterpart->name) : 'Unknown',
                'avatar' => $counterpart?->avatar,
                'role' => $isDoctorView ? 'patient' : 'doctor',
                'subtitle' => $isDoctorView ? 'Patient' : ($c->doctor?->specialization ?? 'Doctor'),
            ],
            'appointment' => $c->appointment ? [
                'id' => $c->appointment->id,
                'date' => $c->appointment->date?->format('Y-m-d'),
                'start_time' => $c->appointment->start_time,
                'end_time' => $c->appointment->end_time,
                'status' => $c->appointment->status,
                'is_video' => $c->appointment->isVideo(),
                'reason' => $c->appointment->reason,
                'can_join_video' => $c->appointment->canJoinVideoNow(),
            ] : null,
            'last_message' => $c->latestMessage ? [
                'id' => $c->latestMessage->id,
                'body' => $c->latestMessage->body ?? ($c->latestMessage->attachment_name ? '📎 '.$c->latestMessage->attachment_name : ''),
                'is_mine' => $c->latestMessage->sender_user_id === $viewer->id,
                'created_at' => $c->latestMessage->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) ($c->unread_count ?? $c->unreadCountFor($viewer)),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'can_send' => $c->isParticipant($viewer),
        ];
    }
}
