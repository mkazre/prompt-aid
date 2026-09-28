<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientDocument extends Model
{
    use HasFactory;

    public const TYPE_ID = 'id';

    public const TYPE_MEDICAL_AID_CARD = 'medical_aid_card';

    protected $fillable = ['patient_profile_id', 'type', 'path', 'original_name'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }
}
