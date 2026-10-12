<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\Support\SupportTicketService;
use App\Support\SupportSettings;
use Illuminate\Http\Request;

/**
 * Help & support tickets on the website (/account/support) for every
 * signed-in role. Every query is pinned to the signed-in user's own tickets.
 */
class SupportController extends Controller
{
    public function index(Request $request)
    {
        return view('account.support.index', [
            'tickets' => SupportTicket::query()
                ->where('user_id', $request->user()->id)
                ->orderByRaw("CASE WHEN status = 'awaiting_reply' THEN 0 WHEN status = 'open' THEN 1 ELSE 2 END")
                ->orderByDesc('last_reply_at')
                ->orderByDesc('id')
                ->paginate(15),
        ]);
    }

    public function create()
    {
        abort_unless(SupportSettings::bool('support_tickets_enabled'), 404);

        return view('account.support.create', ['categories' => SupportTicket::CATEGORIES]);
    }

    public function store(Request $request, SupportTicketService $service)
    {
        abort_unless(SupportSettings::bool('support_tickets_enabled'), 404);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'category' => ['required', 'in:'.implode(',', array_keys(SupportTicket::CATEGORIES))],
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:'.SupportTicketReply::ATTACHMENT_MIMES, 'max:'.SupportTicketReply::ATTACHMENT_MAX_KB],
        ]);

        $ticket = $service->open($request->user(), $data, $request->file('attachment'));

        return redirect()->route('account.support.show', $ticket)->with('success', "Ticket {$ticket->reference} opened. We'll reply here and notify you.");
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        $this->authorizeOwner($request, $ticket);

        $ticket->load(['replies.user']);

        return view('account.support.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $service)
    {
        $this->authorizeOwner($request, $ticket);

        if ($ticket->isClosed()) {
            return back()->withErrors(['message' => 'This ticket is closed. Please open a new one.']);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:'.SupportTicketReply::ATTACHMENT_MIMES, 'max:'.SupportTicketReply::ATTACHMENT_MAX_KB],
        ]);

        $service->userReply($ticket, $request->user(), $data['message'], $request->file('attachment'));

        return redirect()->route('account.support.show', $ticket)->with('success', 'Reply sent.');
    }

    public function status(Request $request, SupportTicket $ticket, SupportTicketService $service)
    {
        $this->authorizeOwner($request, $ticket);

        $data = $request->validate(['status' => ['required', 'in:resolved,open']]);

        if ($ticket->isClosed()) {
            return back();
        }

        $service->userSetStatus($ticket, $data['status']);

        return redirect()->route('account.support.show', $ticket)->with('success', $data['status'] === 'resolved' ? 'Marked as resolved — thanks!' : 'Ticket reopened.');
    }

    protected function authorizeOwner(Request $request, SupportTicket $ticket): void
    {
        abort_unless((int) $ticket->user_id === (int) $request->user()->id, 404);
    }
}
