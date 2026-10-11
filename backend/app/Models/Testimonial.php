<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A patient's experience with a Topic of the month, moderated at /staff → Site → Testimonials. */
class Testimonial extends Model
{
    public const STATUSES = ['pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    protected $fillable = ['user_id', 'health_topic_id', 'name', 'email', 'phone', 'body', 'attachments', 'consent_to_publish', 'status'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'consent_to_publish' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(HealthTopic::class, 'health_topic_id');
    }
}
