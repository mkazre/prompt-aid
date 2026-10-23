<?php

namespace App\Models;

use App\Support\SiteContent;
use Illuminate\Database\Eloquent\Builder;
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
        'payment_policy', 'deposit_percent',
        'user_id', 'specialization', 'qualification', 'experience_years', 'bio',
        'consultation_fee', 'registration_no', 'signature', 'rating_avg', 'rating_count', 'status',
        'is_accepting_appointments', 'availability', 'current_lat', 'current_lng', 'location_updated_at',
        'offers_video', 'last_seen_at', 'last_offered_at', 'last_assigned_at',
    ];

    /**
     * Public traffic-light status shown to patients, driven by the doctor's
     * own `availability` setting (dashboard / mobile app):
     *   green  = available → can see the patient within 30 minutes
     *   orange = busy      → available today, at a booked slot in the queue
     *   red    = offline   → not available today; book a future day
     * Labels/descriptions are admin-editable (SiteContent 'status').
     */
    /** Maps the doctor's stored availability onto the patient-facing colour. */
    public const AVAILABILITY_COLOURS = [
        self::AVAILABLE => 'green',
        self::BUSY => 'orange',
        self::OFFLINE => 'red',
    ];

    /** Specialization text that marks a General Practitioner / Dentist. */
    protected const GP_PATTERNS = ['%general practi%', '%family physician%', '%family medicine%'];

    protected const DENTIST_PATTERNS = ['%dentist%', '%dental%', '%orthodont%', '%oral%'];

    protected function casts(): array
    {
        return [
            'is_accepting_appointments' => 'boolean',
            'offers_video' => 'boolean',
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

    public function liveStatus(): string
    {
        if ($this->status !== 'active' || ! $this->is_accepting_appointments) {
            return 'red';
        }

        return self::AVAILABILITY_COLOURS[$this->availability] ?? 'red';
    }

    public function liveStatusLabel(): string
    {
        return (string) SiteContent::get('status.'.$this->liveStatus().'.label');
    }

    public function liveStatusDescription(): string
    {
        return (string) SiteContent::get('status.'.$this->liveStatus().'.description');
    }

    public function isGeneralPractitioner(): bool
    {
        $spec = mb_strtolower((string) $this->specialization);

        return $spec === 'gp' || str_contains($spec, 'general practi') || str_contains($spec, 'family physician') || str_contains($spec, 'family medicine');
    }

    public function scopeGeneralPractitioners(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('specialization', 'GP');
            foreach (self::GP_PATTERNS as $pattern) {
                $q->orWhere('specialization', 'like', $pattern);
            }
        });
    }

    public function scopeDentists(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (self::DENTIST_PATTERNS as $pattern) {
                $q->orWhere('specialization', 'like', $pattern);
            }
        });
    }

    /** Medical specialists: everyone who is neither a GP nor a dentist. */
    public function scopeSpecialists(Builder $query): Builder
    {
        $query->where('specialization', '!=', 'GP');
        foreach (array_merge(self::GP_PATTERNS, self::DENTIST_PATTERNS) as $pattern) {
            $query->where('specialization', 'not like', $pattern);
        }

        return $query;
    }

    public function scopeOffersVideo(Builder $query): Builder
    {
        return $query->where('offers_video', true);
    }

    /** Green first, then orange, then red — so "see me now" doctors lead the list. */
    public function scopeOrderByLiveStatus(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE WHEN is_accepting_appointments = 1 AND availability = 'available' THEN 0 WHEN is_accepting_appointments = 1 AND availability = 'busy' THEN 1 ELSE 2 END")
            ->orderByDesc('rating_avg');
    }

    /**
     * Opening hours per weekday, e.g. ['Mon' => '08:00–17:00', ...], across
     * every clinic the doctor works at. Days without a schedule are omitted.
     *
     * @return array<string, string>
     */
    public function operatingHours(): array
    {
        $rows = $this->relationLoaded('availabilities') ? $this->availabilities : $this->availabilities()->where('is_active', true)->get();
        $days = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 0];
        $hours = [];

        foreach ($days as $label => $weekday) {
            $dayRows = $rows->where('is_active', true)->where('weekday', $weekday);
            if ($dayRows->isNotEmpty()) {
                $hours[$label] = substr($dayRows->min('start_time'), 0, 5).'–'.substr($dayRows->max('end_time'), 0, 5);
            }
        }

        return $hours;
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

    /** "Dr Jane Smith" — without doubling a title already in the name ("Dr. Dr. …"). */
    public function displayName(): string
    {
        $name = trim((string) $this->user?->name);
        $name = preg_replace('/^(dr\.?\s+)+/i', '', $name);

        return 'Dr '.$name;
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
