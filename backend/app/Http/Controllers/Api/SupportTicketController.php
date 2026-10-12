<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\Support\SupportTicketService;
use App\Support\SupportSettings;
use Illuminate\Http\Request;

/** Help & support tickets for the app ("Help & support" screen), any role. */
class SupportTicketController extends Controller
{
    public function meta()
    {
        return response()->json(['data' => [
            'enabled' => SupportSettings::bool('support_tickets_enabled'),
            'intro' => SupportSettings::str('support_intro'),
            'response_note' => SupportSettings::str('support_response_note'),
            'categories' => collect(SupportTicket::CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'max_attachment_kb' => SupportTicketReply::ATTACHMENT_MAX_KB,
            'attachment_types' => explode(',', SupportTicketReply::ATTACHMENT_MIMES),
        ]]);
    }

    public function index(Request $request)
    {
        $tickets = SupportTicket::query()
            ->where('user_id', $request->user()->id)
            ->orderByRaw("CASE WHEN status = 'awaiting_reply' THEN 0 WHEN status = 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $tickets->map(fn (SupportTicket $t) => $t->toApiArray())->values()]);
    }

    public function store(Request $request, SupportTicketService $service)
    {
        abort_unless(SupportSettings::bool('support_tickets_enabled'), 403, 'Support tickets are switched off right now.');

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'category' => ['required', 'in:'.implode(',', array_keys(SupportTicket::CATEGORIES))],
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:'.SupportTicketReply::ATTACHMENT_MIMES, 'max:'.SupportTicketReply::ATTACHMENT_MAX_KB],
        ]);

        $ticket = $service->open($request->user(), $data, $request->file('attachment'));

        return response()->json(['data' => $ticket->load('replies.user')->toApiArray(true)], 201);
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        $this->authorizeOwner($request, $ticket);

        return response()->json(['data' => $ticket->load('replies.user')->toApiArray(true)]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $service)
    {
        $this->authorizeOwner($request, $ticket);
        abort_if($ticket->isClosed(), 422, 'This ticket is closed. Please open a new one.');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:'.SupportTicketReply::ATTACHMENT_MIMES, 'max:'.SupportTicketReply::ATTACHMENT_MAX_KB],
        ]);

        $service->userReply($ticket, $request->user(), $data['message'], $request->file('attachment'));

        return response()->json(['data' => $ticket->fresh()->load('replies.user')->toApiArray(true)]);
    }

    public function status(Request $request, SupportTicket $ticket, SupportTicketService $service)
    {
        $this->authorizeOwner($request, $ticket);
        abort_if($ticket->isClosed(), 422, 'This ticket is closed.');

        $data = $request->validate(['status' => ['required', 'in:resolved,open']]);
        $service->userSetStatus($ticket, $data['status']);

        return response()->json(['data' => $ticket->fresh()->load('replies.user')->toApiArray(true)]);
    }

    protected function authorizeOwner(Request $request, SupportTicket $ticket): void
    {
        abort_unless((int) $ticket->user_id === (int) $request->user()->id, 404);
    }
}
