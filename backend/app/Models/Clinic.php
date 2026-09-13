<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clinic extends Model
{
    use HasFactory;

    protected $fillable = [
        'clinic_admin_id', 'name', 'slug', 'logo', 'cover_image', 'description',
        'phone', 'email', 'address', 'city', 'state', 'country', 'postal_code',
        'lat', 'lng', 'specialties', 'working_hours', 'status',
    ];

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'working_hours' => 'array',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinic_admin_id');
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(DoctorProfile::class, 'clinic_doctor');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
