<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverProfile extends Model
{
    use HasFactory;

    public const AVAILABLE = 'available';

    public const BUSY = 'busy';

    public const OFFLINE = 'offline';

    protected $fillable = [
        'user_id', 'license_no', 'license_expiry', 'vehicle_make', 'vehicle_model',
        'vehicle_color', 'vehicle_plate_no', 'vehicle_type', 'vehicle_photo',
        'availability', 'current_lat', 'current_lng', 'location_updated_at',
        'rating_avg', 'rating_count', 'status',
    ];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date',
            'location_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }

    public function isAvailable(): bool
    {
        return $this->availability === self::AVAILABLE && $this->status === 'active';
    }
}
