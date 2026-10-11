<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One driver GPS breadcrumb recorded while a ride was active. */
class RideLocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_id', 'driver_profile_id', 'lat', 'lng', 'heading', 'speed_kmh', 'phase', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'heading' => 'float',
            'speed_kmh' => 'float',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
