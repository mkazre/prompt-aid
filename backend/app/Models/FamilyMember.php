<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dependant / family member managed by a patient account. */
class FamilyMember extends Model
{
    use \App\Concerns\Auditable;

    public const RELATIONSHIPS = [
        'spouse' => 'Spouse / partner',
        'child' => 'Child',
        'parent' => 'Parent',
        'sibling' => 'Sibling',
        'grandparent' => 'Grandparent',
        'other' => 'Other',
    ];

    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

    protected $fillable = [
        'patient_profile_id', 'name', 'relationship', 'dob', 'gender', 'id_number',
        'medical_aid_scheme', 'medical_aid_number', 'allergies',
    ];

    protected function casts(): array
    {
        return ['dob' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function relationshipLabel(): string
    {
        return self::RELATIONSHIPS[$this->relationship] ?? ucfirst((string) $this->relationship);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'relationship' => $this->relationship,
            'relationship_label' => $this->relationshipLabel(),
            'dob' => $this->dob?->toDateString(),
            'age' => $this->dob?->age,
            'gender' => $this->gender,
            'id_number' => $this->id_number,
            'medical_aid_scheme' => $this->medical_aid_scheme,
            'medical_aid_number' => $this->medical_aid_number,
            'allergies' => $this->allergies,
        ];
    }
}
