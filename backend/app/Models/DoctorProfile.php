<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DoctorProfile extends Model
{
    use HasFactory, \App\Concerns\Auditable;

    public const AVAILABLE = 'available';

    public const BUSY = 'busy';

    public const OFFLINE = 'offline';

    protected $fillable = [
        'user_id', 'specialization', 'qualification', 'experience_years', 'bio',
        'consultation_fee', 'registration_no', 'signature', 'rating_avg', 'rating_count', 'status',
        'is_accepting_appointments', 'availability', 'current_lat', 'current_lng', 'location_updated_at',
        'last_seen_at', 'last_offered_at', 'last_assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'is_accepting_appointments' => 'boolean',
            'location_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_offered_at' => 'datetime',
            'last_assigned_at' => 'datetime',
        ];
    }

    /**
     * Whether patients should be shown this doctor as bookable right now:
     * both the admin-approved status and the doctor's own toggle must allow it.
     */
    public function isAvailableForBooking(): bool
    {
        return $this->status === 'active' && $this->is_accepting_appointments;
    }

    /**
     * Whether this doctor can be matched into an on-demand video/in-person
     * request right now (the "Uber-style" instant-match path).
     */
    public function isAvailableForMatching(): bool
    {
        return $this->status === 'active' && $this->is_accepting_appointments && $this->availability === self::AVAILABLE;
    }

    /**
     * Human-readable weekly schedule summary, e.g. "Mon–Fri, 08:00–17:00",
     * derived from the doctor's active availability rows across all clinics.
     */
    public function availabilitySummary(): ?string
    {
        $rows = $this->relationLoaded('availabilities') ? $this->availabilities : $this->availabilities()->where('is_active', true)->get();
        $active = $rows->where('is_active', true);

        if ($active->isEmpty()) {
            return null;
        }

        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $weekdays = $active->pluck('weekday')->unique()->sort()->values();

        $labels = $weekdays->map(fn ($w) => $days[$w])->all();
        $dayLabel = implode(', ', $labels);

        $start = $active->min('start_time');
        $end = $active->max('end_time');

        return "{$dayLabel}, ".substr($start, 0, 5).'–'.substr($end, 0, 5);
    }

    /**
     * Free-text doctor search: name, specialization, or a condition/symptom
     * (admin-managed Conditions) whose linked specialization matches.
     */
    public function scopeMatchingSearch(\Illuminate\Database\Eloquent\Builder $query, string $term): \Illuminate\Database\Eloquent\Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $specs = \Illuminate\Support\Facades\Schema::hasTable('conditions')
            ? Condition::query()
                ->where('is_active', true)
                ->whereNotNull('specialization')
                ->where(fn ($c) => $c->where('name', 'like', $like)->orWhere('synonyms', 'like', $like))
                ->pluck('specialization')->unique()->values()->all()
            : [];

        return $query->where(function ($w) use ($like, $specs) {
            $w->whereHas('user', fn ($u) => $u->where('name', 'like', $like))
                ->orWhere('specialization', 'like', $like);

            if ($specs) {
                $w->orWhereIn('specialization', $specs);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class, 'clinic_doctor');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(DoctorTimeOff::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function labRequests(): HasMany
    {
        return $this->hasMany(LabRequest::class);
    }
}
