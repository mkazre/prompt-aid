<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Comms\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Patient web inbox (/account/messages). Same ChatService + policy as the
 * mobile API; the thread polls poll() every few seconds with ?after=<id>.
 */
class MessagesController extends Controller
{
    public function __construct(protected ChatService $chat) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->forParticipant($user)
            ->with(['doctor.user', 'patient.user', 'latestMessage', 'appointment'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where('sender_user_id', '!=', $user->id)])
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->get();

        $active = null;
        if ($request->filled('c')) {
            $active = $conversations->firstWhere('id', (int) $request->input('c'));
        }
        $active ??= $conversations->first();

        $messages = collect();
        if ($active) {
            Gate::authorize('view', $active);
            $messages = $this->chat->messages($active)->map(fn (Message $m) => $m->toChatArray($user));
            $this->chat->markRead($active, $user);
        }

        return view('account.messages', [
            'conversations' => $conversations,
            'active' => $active,
            'threadMessages' => $messages,
        ]);
    }

    /** Start (or reopen) the thread for one of my appointments. */
    public function start(Request $request)
    {
        $data = $request->validate(['appointment_id' => ['required', 'integer']]);
        $appointment = Appointment::query()->find($data['appointment_id']);
        $conversation = $appointment ? $this->chat->forAppointment($appointment, $request->user()) : null;

        if (! $conversation) {
            return redirect()->route('account.messages')->with('error', 'You can only message a doctor you have an appointment with.');
        }

        return redirect()->route('account.messages', ['c' => $conversation->id]);
    }

    public function poll(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $user = $request->user();

        $messages = $this->chat->messages($conversation, $request->integer('after') ?: null);
        if ($messages->isNotEmpty() && $conversation->isParticipant($user)) {
            $this->chat->markRead($conversation, $user);
        }

        return response()->json([
            'data' => $messages->map(fn (Message $m) => $m->toChatArray($user))->values(),
            'last_read_own_id' => $conversation->messages()->where('sender_user_id', $user->id)->whereNotNull('read_at')->max('id'),
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
}
