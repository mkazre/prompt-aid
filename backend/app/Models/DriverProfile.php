<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverProfile extends Model
{
    use HasFactory, \App\Concerns\Auditable;

    /** Live GPS / presence churn — not audited (see Auditable). */
    protected array $auditIgnore = ['current_lat', 'current_lng', 'location_updated_at', 'availability', 'current_heading', 'current_speed_kmh',
        'rating_avg', 'rating_count', 'offers_accepted_count', 'offers_declined_count', 'offers_missed_count', 'rides_cancelled_count'];

    public const AVAILABLE = 'available';

    public const BUSY = 'busy';

    public const OFFLINE = 'offline';

    protected $fillable = [
        'user_id', 'license_no', 'license_expiry', 'vehicle_make', 'vehicle_model',
        'vehicle_color', 'vehicle_plate_no', 'vehicle_type', 'vehicle_photo',
        'availability', 'current_lat', 'current_lng', 'location_updated_at',
        'rating_avg', 'rating_count', 'status',
        'current_heading', 'current_speed_kmh', 'offers_accepted_count', 'offers_declined_count',
        'offers_missed_count', 'rides_cancelled_count',
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

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class)->latest();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RideOffer::class);
    }

    /**
     * 0..1 reliability used to break ties in dispatch ranking: share of
     * offers accepted, minus a penalty per accepted ride later cancelled by
     * the driver. New drivers start at a neutral 0.8.
     */
    public function reliability(): float
    {
        $accepted = (int) $this->offers_accepted_count;
        $total = $accepted + (int) $this->offers_declined_count + (int) $this->offers_missed_count;
        $acceptance = $total >= 3 ? $accepted / $total : 0.8;
        $cancelPenalty = $accepted > 0 ? min(0.5, ((int) $this->rides_cancelled_count / max(1, $accepted)) * 0.5) : 0.0;

        return round(max(0.0, min(1.0, $acceptance - $cancelPenalty)), 2);
    }

    /** Document types that carry an expiry and block dispatch once lapsed. */
    public const EXPIRING_DOCUMENT_TYPES = ['drivers_licence', 'prdp', 'vehicle_registration', 'roadworthy', 'insurance'];

    /**
     * Why this driver may not receive rides right now (compliance), or null.
     * Rule: licence_expiry on the profile is in the past, OR for any expiring
     * document type the driver has APPROVED documents for, none of those
     * approved documents is still valid (expires_at null or >= today).
     * Types never uploaded don't block (uploads weren't mandatory before).
     */
    public function complianceBlocker(): ?string
    {
        $today = now()->startOfDay();

        if ($this->license_expiry && $this->license_expiry->lt($today)) {
            return 'Driving licence expired on '.$this->license_expiry->format('d M Y');
        }

        $docs = $this->relationLoaded('documents') ? $this->documents : $this->documents()->get();
        foreach ($docs->where('status', 'approved')->whereIn('type', self::EXPIRING_DOCUMENT_TYPES)->groupBy('type') as $type => $group) {
            $valid = $group->contains(fn ($d) => $d->expires_at === null || \Illuminate\Support\Carbon::parse($d->expires_at)->gte($today));
            if (! $valid) {
                return (DriverDocument::TYPES[$type] ?? $type).' expired';
            }
        }

        return null;
    }

    public function initials(): string
    {
        $name = trim((string) $this->user?->name);
        $parts = preg_split('/\s+/', $name) ?: [];

        return strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice(array_filter($parts), 0, 2)))) ?: 'D';
    }

    public function isAvailable(): bool
    {
        return $this->availability === self::AVAILABLE && $this->status === 'active';
    }
}
