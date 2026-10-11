<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'sender_user_id', 'body',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'read_at',
    ];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
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
     * Short-lived signed link to the private file. Only ever generated for
     * callers that already passed ConversationPolicy::view, so possession of
     * the signature is the authorisation (lets <img>, the RN Image component
     * and Linking open it without a bearer header).
     */
    public function attachmentUrl(): ?string
    {
        if (! $this->hasAttachment()) {
            return null;
        }

        // Relative signature (validated with `signed:relative`) so an
        // http/https or host mismatch behind shared hosting can't break it.
        return url(URL::temporarySignedRoute('chat.attachment', now()->addMinutes(60), ['message' => $this->id], false));
    }

    /** Stable API shape shared by the mobile API, web account and Filament. */
    public function toChatArray(?User $viewer = null): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_user_id' => $this->sender_user_id,
            'sender_name' => $this->sender?->name,
            'is_mine' => $viewer ? $this->sender_user_id === $viewer->id : false,
            'body' => $this->body,
            'attachment' => $this->hasAttachment() ? [
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'size' => $this->attachment_size,
                'is_image' => $this->isImage(),
                'url' => $this->attachmentUrl(),
            ] : null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
