<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Services\Notifications\InAppNotifier;
use App\Support\StaffNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every write to a support ticket goes through here, from the web account,
 * the mobile API and the Staff panel, so status changes and alerts stay
 * identical everywhere:
 *   - user opens / replies  → status "open"  → StaffNotifier to the assignee
 *     (or super admins + staff with support-tickets.edit when unassigned);
 *   - staff replies          → status "awaiting_reply" → InAppNotifier to the user.
 */
class SupportTicketService
{
    public function open(User $user, array $data, ?UploadedFile $file = null): SupportTicket
    {
        $ticket = DB::transaction(function () use ($user, $data, $file) {
            $ticket = SupportTicket::query()->create([
                'user_id' => $user->id,
                'subject' => mb_substr(trim((string) $data['subject']), 0, 255),
                'category' => array_key_exists($data['category'] ?? '', SupportTicket::CATEGORIES) ? $data['category'] : 'other',
                'priority' => 'normal',
                'status' => SupportTicket::STATUS_OPEN,
                'last_reply_at' => now(),
                'last_reply_by' => 'user',
            ]);

            $this->addReply($ticket, $user, (string) $data['message'], false, $file);

            return $ticket;
        });

        $this->alertStaff(
            $ticket,
            "New support ticket {$ticket->reference}: {$ticket->subject}",
            $ticket->categoryLabel().' · from '.$user->name.' ('.$this->roleLabel($user).')'."\n".Str::limit((string) $data['message'], 180),
        );

        return $ticket;
    }

    public function userReply(SupportTicket $ticket, User $user, string $body, ?UploadedFile $file = null): SupportTicketReply
    {
        $reply = $this->addReply($ticket, $user, $body, false, $file);

        $ticket->forceFill([
            'status' => SupportTicket::STATUS_OPEN,
            'last_reply_at' => now(),
            'last_reply_by' => 'user',
        ])->save();

        $this->alertStaff(
            $ticket,
            "{$user->name} replied on {$ticket->reference}",
            Str::limit($body, 200),
        );

        return $reply;
    }

    public function staffReply(SupportTicket $ticket, User $staff, string $body, ?string $attachmentPath = null, ?string $attachmentName = null, ?string $newStatus = null): SupportTicketReply
    {
        $reply = SupportTicketReply::query()->create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $staff->id,
            'is_staff' => true,
            'body' => $body,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentPath ? ($attachmentName ?: basename($attachmentPath)) : null,
            'attachment_mime' => $attachmentPath ? (Storage::disk(SupportTicketReply::ATTACHMENT_DISK)->mimeType($attachmentPath) ?: null) : null,
        ]);

        $status = $newStatus && array_key_exists($newStatus, SupportTicket::STATUSES) ? $newStatus : SupportTicket::STATUS_AWAITING_REPLY;

        $ticket->forceFill([
            'status' => $status,
            'last_reply_at' => now(),
            'last_reply_by' => 'staff',
            'assigned_to' => $ticket->assigned_to ?? $staff->id,
        ])->save();

        app(InAppNotifier::class)->send(
            $ticket->user_id,
            'general',
            "Support replied: {$ticket->subject}",
            Str::limit($body, 200),
            [
                'url' => route('account.support.show', $ticket, false),
                'route' => 'support_ticket',
                'params' => ['ticket_id' => $ticket->id],
            ],
        );

        return $reply;
    }

    /** Status change by the ticket's owner (mark resolved / reopen). */
    public function userSetStatus(SupportTicket $ticket, string $status): void
    {
        if (! in_array($status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_OPEN], true)) {
            return;
        }

        $ticket->update(['status' => $status]);
    }

    protected function addReply(SupportTicket $ticket, User $author, string $body, bool $isStaff, ?UploadedFile $file): SupportTicketReply
    {
        $path = $name = $mime = null;

        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $path = $file->storeAs('support/'.$ticket->id, Str::random(40).'.'.$ext, SupportTicketReply::ATTACHMENT_DISK);
            $name = mb_substr($file->getClientOriginalName() ?: ('attachment.'.$ext), 0, 255);
            $mime = $file->getMimeType();
        }

        return SupportTicketReply::query()->create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'is_staff' => $isStaff,
            'body' => trim($body),
            'attachment_path' => $path ?: null,
            'attachment_name' => $name,
            'attachment_mime' => $mime,
        ]);
    }

    /** @return Collection<int, User> */
    public function staffRecipients(SupportTicket $ticket): Collection
    {
        if ($ticket->assigned_to && ($assignee = User::query()->find($ticket->assigned_to)) && $assignee->status === 'active') {
            return collect([$assignee]);
        }

        return User::query()
            ->whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN])
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $u) => $u->isSuperAdmin() || $u->hasPermission('support-tickets.edit'))
            ->values();
    }

    protected function alertStaff(SupportTicket $ticket, string $title, string $body): void
    {
        try {
            $recipients = $this->staffRecipients($ticket);
            if ($recipients->isEmpty()) {
                return;
            }

            StaffNotifier::alert(
                $recipients->all(),
                $title,
                $body,
                icon: 'heroicon-o-lifebuoy',
                color: $ticket->priority === 'urgent' ? 'danger' : 'warning',
                url: $this->staffUrl($ticket),
                actionLabel: 'Open ticket',
            );
        } catch (\Throwable $e) {
            Log::warning('[Support] staff alert failed: '.$e->getMessage());
        }
    }

    public function staffUrl(SupportTicket $ticket): string
    {
        try {
            return \App\Filament\Resources\SupportTickets\SupportTicketResource::getUrl('edit', ['record' => $ticket], panel: 'admin');
        } catch (\Throwable $e) {
            return url('/staff/support-tickets/'.$ticket->id.'/edit');
        }
    }

    protected function roleLabel(User $user): string
    {
        return match ($user->role) {
            User::ROLE_PATIENT => 'patient',
            User::ROLE_DOCTOR => 'doctor',
            User::ROLE_DRIVER => 'driver',
            User::ROLE_PHARMACY_ADMIN => 'pharmacy',
            User::ROLE_THIRD_PARTY => 'partner',
            default => str_replace('_', ' ', (string) $user->role),
        };
    }
}
