<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_CLINIC_ADMIN = 'clinic_admin';

    public const ROLE_DOCTOR = 'doctor';

    public const ROLE_DRIVER = 'driver';

    public const ROLE_PATIENT = 'patient';

    public const ROLE_THIRD_PARTY = 'third_party';

    public const ROLE_PHARMACY_ADMIN = 'pharmacy_admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'avatar',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function doctorProfile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class);
    }

    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function clinicsAdministered(): HasMany
    {
        return $this->hasMany(Clinic::class, 'clinic_admin_id');
    }

    public function thirdPartyProfile(): HasOne
    {
        return $this->hasOne(ThirdPartyProfile::class);
    }

    public function pharmacies(): HasMany
    {
        return $this->hasMany(Pharmacy::class, 'vendor_id');
    }

    public function isThirdParty(): bool
    {
        return $this->role === self::ROLE_THIRD_PARTY;
    }

    public function isPharmacyAdmin(): bool
    {
        return $this->role === self::ROLE_PHARMACY_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isClinicAdmin(): bool
    {
        return $this->role === self::ROLE_CLINIC_ADMIN;
    }

    public function isDoctor(): bool
    {
        return $this->role === self::ROLE_DOCTOR;
    }

    public function isDriver(): bool
    {
        return $this->role === self::ROLE_DRIVER;
    }

    public function isPatient(): bool
    {
        return $this->role === self::ROLE_PATIENT;
    }

    /**
     * Filament admin panel access — restricted to staff-side roles.
     */
    public function canAccessPanel(\Filament\Panels\Panel $panel): bool
    {
        return in_array($this->role, [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CLINIC_ADMIN,
            self::ROLE_DOCTOR,
            self::ROLE_THIRD_PARTY,
            self::ROLE_PHARMACY_ADMIN,
        ], true) && $this->status === 'active';
    }
}
