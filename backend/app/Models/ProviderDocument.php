<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_application_id', 'label', 'path', 'original_name',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(ProviderApplication::class, 'provider_application_id');
    }
}
