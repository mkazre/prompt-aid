<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A doctor a patient saved to "My doctors". */
class FavouriteDoctor extends Model
{
    protected $fillable = ['patient_profile_id', 'doctor_profile_id'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }
}
