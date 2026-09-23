<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TriageSubmission extends Model
{
    use HasFactory;

    public const LEVEL_RED = 'red';

    public const LEVEL_ORANGE = 'orange';

    public const LEVEL_YELLOW = 'yellow';

    public const LEVEL_GREEN = 'green';

    protected $fillable = [
        'reference', 'level', 'user_id', 'age_band', 'pregnant', 'symptoms',
        'discriminators', 'observations', 'reasons', 'facility_types', 'ip', 'staff_notified',
    ];

    protected function casts(): array
    {
        return [
            'pregnant' => 'boolean',
            'symptoms' => 'array',
            'discriminators' => 'array',
            'observations' => 'array',
            'reasons' => 'array',
            'facility_types' => 'array',
            'staff_notified' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
