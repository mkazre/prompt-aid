<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ride extends Model
{
    use HasFactory, \App\Concerns\Auditable;

    /** High-frequency / derived fields excluded from the audit trail (ETA refresh, simulator, dispatch counters). */
    protected array $auditIgnore = [
        'eta_minutes', 'eta_updated_at', 'simulation', 'dispatch_attempts', 'dispatch_started_at',
        'route_geometry', 'fare_breakdown', 'updated_at',
    ];

    /** Secrets / bulky blobs never written to audit_logs in clear. */
    protected array $auditRedact = ['trip_pin', 'share_token', 'quote_token', 'route_geometry'];

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DRIVER_ENROUTE = 'driver_enroute';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Terminal statuses: a ride in one of these is CLOSED everywhere (patient
     * app/web, driver, admin) and never resurfaces. "Expired" rides (no
     * driver found in time, stale scheduled/abandoned trips) are stored as
     * status=cancelled + cancelled_by=system + dispatch_state=expired so that
     * already-installed app builds (which only know completed/cancelled)
     * treat them as closed too.
     */
    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    public const DISPATCH_EXPIRED = 'expired';

    public const CANCELLED_BY_SYSTEM = 'system';

    protected $fillable = [
        'ride_ref', 'patient_profile_id', 'driver_profile_id', 'appointment_id', 'vehicle_type',
        'pickup_address', 'pickup_lat', 'pickup_lng', 'dropoff_address', 'dropoff_lat', 'dropoff_lng',
        'status', 'distance_km', 'eta_minutes', 'fare_estimate', 'fare_final', 'cancel_reason',
        'requested_at', 'accepted_at', 'started_at', 'completed_at',
        'ride_series_id', 'return_of_ride_id', 'is_return', 'scheduled_for', 'wait_and_return', 'priority',
        // Shuttle v2
        'trip_pin', 'share_token', 'dispatch_state', 'dispatch_started_at', 'dispatch_attempts',
        'quoted_fare', 'surge_multiplier', 'quote_token', 'quote_expires_at', 'fare_breakdown',
        'route_distance_km', 'route_duration_min', 'route_geometry', 'eta_updated_at', 'driver_arrived_at',
        'cancelled_at', 'cancelled_by', 'cancellation_fee', 'cancellation_fee_status',
        'patient_rating', 'patient_rating_comment', 'simulation',
        // Unified dispatch
        'radius_km', 'dispatch_mode', 'pickup_notes', 'booked_by_user_id', 'pin_attempts', 'pin_locked_until', 'closed_reason',
    ];

    /** Dispatch states (rides.dispatch_state) while status = requested. */
    public const DISPATCH_SEARCHING = 'searching';

    public const DISPATCH_QUEUE = 'queue';

    public const DISPATCH_NO_DRIVERS = 'no_drivers';

    public const DISPATCH_ASSIGNED = 'assigned';

    /** Statuses in which a driver is attached and heading to / with the patient. */
    public const DRIVER_ACTIVE_STATUSES = ['accepted', 'driver_enroute', 'arrived', 'in_progress'];

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
            'dispatch_started_at' => 'datetime',
            'quote_expires_at' => 'datetime',
            'eta_updated_at' => 'datetime',
            'driver_arrived_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'fare_breakdown' => 'array',
            'route_geometry' => 'array',
            'simulation' => 'array',
            'pin_locked_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ride) {
            $ride->ride_ref ??= 'RIDE-'.strtoupper(uniqid());
            $ride->requested_at ??= now();
            if (! self::isUsablePin($ride->trip_pin)) {
                $ride->trip_pin = self::generatePin();
            }
            $ride->share_token ??= \Illuminate\Support\Str::random(40);
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

    public function locations(): HasMany
    {
        return $this->hasMany(RideLocation::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RideOffer::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(RideCharge::class);
    }

    public function hasDriverEnRoute(): bool
    {
        return $this->driver_profile_id !== null && in_array($this->status, self::DRIVER_ACTIVE_STATUSES, true);
    }

    public function isSimulated(): bool
    {
        return is_array($this->simulation) && ! empty($this->simulation['active']);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /**
     * Truly active = not terminal AND not stale. Stale rides (abandoned
     * searches, scheduled pickups long past, trips with no driver activity
     * for hours) are closed by App\Services\Dispatch\CloseOutService, but
     * until that runs they must never be shown as live to anyone.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal() && ! $this->isStale();
    }

    /** Effective search radius for this ride (patient choice clamped to admin limits). */
    public function searchRadiusKm(): float
    {
        return \App\Support\DispatchSettings::clampRadius($this->radius_km);
    }

    public function isStale(): bool
    {
        if ($this->isTerminal()) {
            return false;
        }
        $settings = \App\Support\DispatchSettings::class;
        $now = now();

        if ($this->status === self::STATUS_REQUESTED) {
            if ($this->scheduled_for) {
                $grace = max(15, $settings::int('dispatch_ride_scheduled_grace_minutes'));

                return $this->scheduled_for->lt($now->copy()->subMinutes($grace));
            }
            $mins = $settings::int('dispatch_ride_searching_expiry_minutes');
            if ($mins > 0 && $this->dispatch_state === self::DISPATCH_QUEUE) {
                $mins = max(60, $mins * 4); // waiting for a manual (admin) assignment / open-queue claim
            }
            $since = $this->dispatch_started_at ?? $this->requested_at ?? $this->created_at;

            return $mins > 0 && $since && $since->lt($now->copy()->subMinutes(max(5, $mins)));
        }

        $hours = $settings::int('dispatch_ride_active_stale_hours');
        if ($hours <= 0) {
            return false;
        }
        $last = collect([$this->updated_at, $this->accepted_at, $this->started_at, $this->driver_arrived_at, $this->relationLoaded('driver') ? $this->driver?->location_updated_at : null])
            ->filter()->max();

        return $last !== null && $last->lt($now->copy()->subHours($hours));
    }

    /** Admin/patient-facing: expired (closed by the system) vs cancelled by a person. */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_CANCELLED && $this->cancelled_by === self::CANCELLED_BY_SYSTEM;
    }

    /** Scope: rides that are not terminal (stale ones are closed by the tick; callers wanting "live" also filter isActive()). */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', self::TERMINAL);
    }

    /** Random 4-digit PIN, never trivial (0000, 1111, 1234...). */
    public static function generatePin(): string
    {
        do {
            $pin = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (! self::isUsablePin($pin));

        return $pin;
    }

    public static function isUsablePin(?string $pin): bool
    {
        if ($pin === null || ! preg_match('/^\d{4}$/', $pin)) {
            return false;
        }
        if (count(array_unique(str_split($pin))) === 1) {
            return false; // 0000, 1111 ...
        }

        return ! in_array($pin, ['1234', '4321', '0123', '1212', '6969', '1004', '2580'], true);
    }
}
