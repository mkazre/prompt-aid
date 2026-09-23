<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ride extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DRIVER_ENROUTE = 'driver_enroute';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'ride_ref', 'patient_profile_id', 'driver_profile_id', 'appointment_id', 'vehicle_type',
        'pickup_address', 'pickup_lat', 'pickup_lng', 'dropoff_address', 'dropoff_lat', 'dropoff_lng',
        'status', 'distance_km', 'eta_minutes', 'fare_estimate', 'fare_final', 'cancel_reason',
        'requested_at', 'accepted_at', 'started_at', 'completed_at',
        'ride_series_id', 'return_of_ride_id', 'is_return', 'scheduled_for', 'wait_and_return', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'is_return' => 'boolean',
            'wait_and_return' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ride) {
            $ride->ride_ref ??= 'RIDE-'.strtoupper(uniqid());
            $ride->requested_at ??= now();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(RideStatusEvent::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(RideReview::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(RideSeries::class, 'ride_series_id');
    }

    public function returnOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_of_ride_id');
    }

    public function returnLeg(): HasOne
    {
        return $this->hasOne(self::class, 'return_of_ride_id');
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }
}
