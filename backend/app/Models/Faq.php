<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Help centre question + rich-text answer, shown on /help and in the app. */
class Faq extends Model
{
    public const AUDIENCES = [
        'all' => 'Everyone',
        'patient' => 'Patients',
        'doctor' => 'Doctors',
        'driver' => 'Drivers',
    ];

    protected $fillable = ['faq_category_id', 'question', 'answer', 'audience', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }

    /**
     * Active FAQs a viewer should see. Guests and staff see everything
     * (the public page lists every audience, labelled); signed-in
     * patients/doctors/drivers see "all" plus their own audience.
     */
    public function scopeVisibleTo(Builder $query, ?string $role, bool $everything = false): Builder
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('faq_category_id')
                ->orWhereHas('category', fn (Builder $c) => $c->where('is_active', true)));

        if ($everything || ! in_array($role, ['patient', 'doctor', 'driver'], true)) {
            return $query;
        }

        return $query->whereIn('audience', ['all', $role]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q->where('question', 'like', $like)->orWhere('answer', 'like', $like));
    }

    /** @return Collection<int, Faq> */
    public static function forViewer(?string $role, ?string $term = null, bool $everything = false): Collection
    {
        return static::query()
            ->with('category')
            ->visibleTo($role, $everything)
            ->search($term)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /** Plain-text answer (for the app and search snippets). */
    public function answerText(): string
    {
        $html = preg_replace('/<\s*(br|\/p|\/li|\/h[1-6])\s*\/?>/i', "$0\n", (string) $this->answer);

        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5)));
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer_html' => (string) $this->answer,
            'answer' => $this->answerText(),
            'audience' => $this->audience,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug] : null,
        ];
    }
}
