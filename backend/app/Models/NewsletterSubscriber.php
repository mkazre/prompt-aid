<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/** Footer newsletter signup, managed at /staff → Support → Newsletter. */
class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'email', 'name', 'user_id', 'source', 'consented_at', 'consent_text',
        'ip_address', 'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSubscribed(): bool
    {
        return $this->unsubscribed_at === null;
    }

    /** Permanent signed one-click unsubscribe link (for every mailing). */
    public function unsubscribeUrl(): string
    {
        // Relative signature (signed:relative) so an http/https or host mismatch behind
        // shared hosting can't break the link.
        return url(URL::signedRoute('newsletter.unsubscribe', ['subscriber' => $this->id], null, false));
    }
}
