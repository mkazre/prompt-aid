<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderApplication extends Model
{
    use HasFactory, \App\Concerns\Auditable;

    public const TYPE_CLINIC = 'clinic';

    public const TYPE_DOCTOR = 'doctor';

    public const TYPE_PHARMACY = 'pharmacy';

    public const TYPE_THIRD_PARTY = 'third_party';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'type', 'business_name', 'contact_name', 'email', 'phone',
        'registration_no', 'hpcsa_sapc_no', 'status', 'rejection_reason',
        'reviewed_by', 'reviewed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProviderDocument::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
