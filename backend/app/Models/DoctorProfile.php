<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DoctorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'specialization', 'qualification', 'experience_years', 'bio',
        'consultation_fee', 'registration_no', 'signature', 'rating_avg', 'rating_count', 'status',
        'is_accepting_appointments',
    ];

    protected $casts = [
        'is_accepting_appointments' => 'boolean',
    ];

    /**
     * Whether patients should be shown this doctor as bookable right now:
     * both the admin-approved status and the doctor's own toggle must allow it.
     */
    public function isAvailableForBooking(): bool
    {
        return $this->status === 'active' && $this->is_accepting_appointments;
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
