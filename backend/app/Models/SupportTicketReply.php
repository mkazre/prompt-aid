<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/** One message in a support ticket thread — from the user or from staff. */
class SupportTicketReply extends Model
{
    /** Private disk, served through a signed route (never public). */
    public const ATTACHMENT_DISK = 'local';

    public const ATTACHMENT_MIMES = 'jpg,jpeg,png,webp,heic,heif,gif,pdf';

    public const ATTACHMENT_MAX_KB = 10240;

    protected $fillable = [
        'support_ticket_id', 'user_id', 'is_staff', 'body',
        'attachment_path', 'attachment_name', 'attachment_mime',
    ];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAttachment(): bool
    {
        return (bool) $this->attachment_path;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->attachment_mime, 'image/');
    }

    /**
     * Short-lived relative signed URL (validated with `signed:relative`).
     * Only minted for people who can already see the ticket.
     */
    public function attachmentUrl(): ?string
    {
        if (! $this->hasAttachment()) {
            return null;
        }

        return url(URL::temporarySignedRoute('support.attachment', now()->addMinutes(60), ['reply' => $this->id], false));
    }

    public function authorLabel(): string
    {
        if ($this->is_staff) {
            return 'PromptAid support';
        }

        return $this->user?->name ?? 'You';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'is_staff' => $this->is_staff,
            'author' => $this->is_staff ? 'PromptAid support' : ($this->user?->name ?? 'You'),
            'staff_name' => $this->is_staff ? $this->user?->name : null,
            'body' => $this->body,
            'attachment' => $this->hasAttachment() ? [
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'is_image' => $this->isImage(),
                'url' => $this->attachmentUrl(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
