<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RideRateCard extends Model
{
    protected $fillable = [
        'vehicle_type', 'base_fare', 'per_km_rate', 'per_minute_rate', 'minimum_fare', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function estimate(float $distanceKm, int $etaMinutes = 0): float
    {
        $fare = (float) $this->base_fare
            + ($distanceKm * (float) $this->per_km_rate)
            + ($etaMinutes * (float) $this->per_minute_rate);

        return round(max($fare, (float) $this->minimum_fare), 2);
    }
}
