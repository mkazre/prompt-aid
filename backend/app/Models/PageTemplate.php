<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'kind', 'entity_type', 'conditions', 'priority', 'page_id', 'is_default'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
