<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Editorial health tips/articles shown in the "Health articles" widget
 * (app + web dashboard) and at /health-articles. Managed by super admins
 * in Site → Health Articles.
 */
class HealthArticle extends Model
{
    protected $fillable = [
        'title', 'slug', 'category', 'excerpt', 'body', 'image', 'read_minutes', 'published_at', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_published' => 'boolean',
            'read_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            if (blank($article->slug)) {
                $article->slug = Str::slug($article->title);
            }
            if ($article->is_published && ! $article->published_at) {
                $article->published_at = now();
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at');
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http') ? $this->image : Storage::disk('public')->url($this->image);
    }

    /** @return array<string, mixed> */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'category' => $this->category,
            'excerpt' => $this->excerpt,
            'image' => $this->imageUrl(),
            'read_minutes' => $this->read_minutes,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    /**
     * Starter content seeded on deploy (general public-health guidance,
     * not personalised medical advice).
     */
    public const STARTER_ARTICLES = [
        [
            'title' => 'Spot a stroke fast: remember F.A.S.T.',
            'slug' => 'spot-a-stroke-fast',
            'category' => 'Emergency',
            'excerpt' => 'Face drooping, Arm weakness, Speech difficulty — Time to call for help. Every minute counts.',
            'body' => "A stroke happens when blood flow to part of the brain is cut off. Fast treatment saves brain tissue, so knowing the signs matters.\n\nF — Face: ask the person to smile. Does one side droop?\nA — Arms: ask them to raise both arms. Does one drift down?\nS — Speech: is their speech slurred or strange?\nT — Time: if you see any of these, call an ambulance immediately and note the time symptoms started.\n\nDo not give the person anything to eat or drink, and do not let them drive themselves. Use the Emergency button in Prompt Aid to find the nearest facility that can help.",
            'read_minutes' => 2,
        ],
        [
            'title' => 'Staying hydrated in the heat',
            'slug' => 'staying-hydrated-in-the-heat',
            'category' => 'Everyday health',
            'excerpt' => 'Children, older adults and people with chronic conditions dehydrate faster. Here is what to watch for.',
            'body' => "Hot days increase how much water your body loses through sweat. Mild dehydration causes thirst, headaches, tiredness and dark urine.\n\nTips:\n• Drink water regularly through the day — don't wait until you are thirsty.\n• Keep babies and young children out of direct sun and offer fluids often.\n• Older adults may not feel thirst as strongly; check in on them.\n• Limit alcohol and very sugary drinks.\n\nSeek care urgently for confusion, fainting, no urine for 8 hours or more, or a very high temperature. These can be signs of heat stroke.",
            'read_minutes' => 3,
        ],
        [
            'title' => 'GP, clinic or emergency room?',
            'slug' => 'gp-clinic-or-emergency-room',
            'category' => 'Using Prompt Aid',
            'excerpt' => 'Choosing the right place for care gets you seen sooner — and keeps emergency rooms free for emergencies.',
            'body' => "Not every health problem needs an emergency room.\n\nSee a GP or clinic (in person or by video) for: colds and flu, mild fevers, rashes, repeat prescriptions, check-ups and managing chronic conditions.\n\nGo to an emergency unit for: chest pain, difficulty breathing, severe bleeding, signs of stroke, serious injuries, seizures or loss of consciousness.\n\nNot sure? Start emergency triage in the app — it asks a few quick questions and points you to the right level of care, with the nearest facility that can treat you.",
            'read_minutes' => 2,
        ],
    ];
}
