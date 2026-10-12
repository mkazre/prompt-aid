<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A Help centre section (/help), managed at /staff → Support → FAQ categories. */
class FaqCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category) {
            if (! $category->slug) {
                $base = Str::slug($category->name) ?: 'section';
                $slug = $base;
                $i = 2;
                while (static::query()->where('slug', $slug)->whereKeyNot($category->id)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $category->slug = $slug;
            }
        });
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class)->orderBy('sort')->orderBy('id');
    }
}
