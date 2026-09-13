<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThirdPartyProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'company_name', 'service_type', 'license_no', 'description',
        'logo', 'rating_avg', 'rating_count', 'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labRequests(): HasMany
    {
        return $this->hasMany(LabRequest::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
