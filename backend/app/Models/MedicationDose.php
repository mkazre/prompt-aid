<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sent-log + taken-log row for one dose (medication × day × time). */
class MedicationDose extends Model
{
    protected $fillable = ['medication_id', 'dose_date', 'dose_time', 'reminder_sent_at', 'taken_at'];

    protected function casts(): array
    {
        return [
            'dose_date' => 'date',
            'reminder_sent_at' => 'datetime',
            'taken_at' => 'datetime',
        ];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
