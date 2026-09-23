<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RideSeries extends Model
{
    use HasFactory;

    protected $fillable = ['patient_profile_id', 'pattern', 'pickup', 'dropoff', 'vehicle_type', 'active'];

    protected function casts(): array
    {
        return [
            'pattern' => 'array',
            'pickup' => 'array',
            'dropoff' => 'array',
            'active' => 'boolean',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }
}
