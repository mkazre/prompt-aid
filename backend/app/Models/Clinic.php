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
        'payment_policy', 'deposit_percent',
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

    /** One-line address used as a shuttle drop-off label. */
    public function shuttleAddress(): string
    {
        return implode(', ', array_filter([$this->name, $this->address, $this->city]));
    }

    /** Link to the shuttle page with this clinic pre-filled as the drop-off. */
    public function shuttleUrl(): string
    {
        $params = ['to' => $this->shuttleAddress()];

        if ($this->lat !== null && $this->lng !== null) {
            $params['to_lat'] = (string) $this->lat;
            $params['to_lng'] = (string) $this->lng;
        }

        return route('shuttle', $params).'#request';
    }
}
