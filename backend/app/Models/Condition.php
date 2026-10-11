<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A searchable condition/symptom ("Back pain", "Acne") linked to the doctor
 * specialization that treats it. Managed in the Staff panel (super admin).
 */
class Condition extends Model
{
    protected $fillable = ['name', 'slug', 'synonyms', 'specialization', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $condition) {
            if (blank($condition->slug)) {
                $condition->slug = Str::slug($condition->name);
            }
        });

        static::saved(fn () => Cache::forget('search.conditions'));
        static::deleted(fn () => Cache::forget('search.conditions'));
    }

    /**
     * @return array<int, string>
     */
    public function synonymList(): array
    {
        return collect(explode(',', (string) $this->synonyms))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }
}
