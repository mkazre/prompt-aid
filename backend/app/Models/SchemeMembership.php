<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchemeMembership extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_profile_id', 'medical_scheme_id', 'member_number', 'dependant_code',
        'main_member_name', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(MedicalScheme::class, 'medical_scheme_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
