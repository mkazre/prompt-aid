<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Raw log of every gateway webhook / ITN / notify call, verified or not. */
class PaymentEvent extends Model
{
    protected $fillable = [
        'payment_intent_id', 'gateway', 'event_type', 'reference', 'verified',
        'verification_note', 'outcome', 'ip', 'payload', 'headers',
    ];

    protected function casts(): array
    {
        return ['verified' => 'boolean', 'payload' => 'array', 'headers' => 'array'];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
