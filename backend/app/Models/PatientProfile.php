<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'dob', 'gender', 'blood_group', 'address', 'lat', 'lng',
        'emergency_contact_name', 'emergency_contact_phone', 'allergies', 'chronic_conditions',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }

    public function rideSeries(): HasMany
    {
        return $this->hasMany(RideSeries::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function labRequests(): HasMany
    {
        return $this->hasMany(LabRequest::class);
    }

    public function prescriptionUploads(): HasMany
    {
        return $this->hasMany(PrescriptionUpload::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
