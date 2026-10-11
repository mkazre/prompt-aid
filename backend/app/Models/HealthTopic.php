<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A "Topic of the month" article (home page CTA → /topic-of-the-month). */
class HealthTopic extends Model
{
    protected $fillable = ['title', 'slug', 'month', 'summary', 'body', 'image', 'attachment', 'is_published'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'is_published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $topic) {
            if (! $topic->slug) {
                $base = Str::slug($topic->title) ?: 'topic';
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->where('id', '!=', $topic->id ?? 0)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $topic->slug = $slug;
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereDate('month', '<=', now()->endOfMonth());
    }

    /** The newest published topic whose month has started. */
    public static function current(): ?self
    {
        return static::query()->published()->orderByDesc('month')->orderByDesc('id')->first();
    }

    public function testimonials(): HasMany
    {
        return $this->hasMany(Testimonial::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
