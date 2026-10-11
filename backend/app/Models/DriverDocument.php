<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocument extends Model
{
    public const TYPES = [
        'drivers_licence' => 'Driving licence',
        'prdp' => 'Professional driving permit (PrDP)',
        'vehicle_registration' => 'Vehicle registration',
        'roadworthy' => 'Roadworthy certificate',
        'insurance' => 'Vehicle insurance',
        'id_document' => 'ID document',
        'other' => 'Other',
    ];

    protected $fillable = ['driver_profile_id', 'type', 'path', 'original_name', 'expires_at', 'status', 'review_note'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }
}
