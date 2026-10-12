<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A /contact form submission — the inbox at /staff → Support → Contact messages. */
class ContactMessage extends Model
{
    public const STATUSES = [
        'new' => 'New',
        'in_progress' => 'In progress',
        'replied' => 'Replied',
        'closed' => 'Closed',
        'spam' => 'Spam',
    ];

    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'subject', 'message', 'status',
        'read_at', 'admin_notes', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mailtoUrl(): string
    {
        $subject = 'Re: '.($this->subject ?: 'Your message to PromptAid');
        $body = "Hi {$this->name},\n\n\n\n---\nYou wrote on ".$this->created_at?->format('j M Y H:i').":\n".$this->message;

        return 'mailto:'.$this->email.'?subject='.rawurlencode($subject).'&body='.rawurlencode($body);
    }
}
