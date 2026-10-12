<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorDelayNotice extends Model
{
    protected $fillable = [
        'doctor_profile_id', 'sent_by_user_id', 'minutes', 'message', 'for_date',
        'patients_notified', 'appointment_ids', 'source',
    ];

    protected function casts(): array
    {
        return [
            'for_date' => 'date',
            'appointment_ids' => 'array',
            'minutes' => 'integer',
            'patients_notified' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
