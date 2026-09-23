<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'title', 'slug', 'kind', 'entity_type', 'status', 'seo_title', 'seo_description',
        'og_image', 'is_home', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->whereNull('parent_id')->orderBy('sort');
    }

    public function allBlocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->orderBy('sort');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest('created_at');
    }

    public function template(): HasMany
    {
        return $this->hasMany(PageTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
