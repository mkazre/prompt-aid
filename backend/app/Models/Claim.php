<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Claim extends Model
{
    use HasFactory, \App\Concerns\Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PART_PAID = 'part_paid';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'claimable_type', 'claimable_id', 'scheme_membership_id', 'status', 'rejection_reason',
        'submitted_at', 'scheme_ref', 'amount_claimed', 'amount_paid', 'response',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'response' => 'array',
        ];
    }

    public function claimable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function claimRef(): Attribute
    {
        return Attribute::get(fn () => sprintf('CLM-%04d', $this->id));
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchemeMembership::class, 'scheme_membership_id');
    }
}
