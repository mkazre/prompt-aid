<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A published version of a legal document (terms / privacy / POPIA
 * health-data notice). The wording lives in the CMS page `page_slug`; this
 * row is the version marker consents are recorded against.
 *
 * Publishing a new version with requires_reconsent = true makes the
 * RequireCurrentConsents middleware prompt every logged-in web user who
 * hasn't accepted it yet (and the API's /privacy/consents/required report it
 * as blocking).
 */
class LegalDocument extends Model
{
    use \App\Concerns\Auditable;

    public const CACHE_KEY = 'legal_documents.current';

    protected $fillable = [
        'purpose', 'version', 'title', 'page_slug', 'summary_of_changes',
        'effective_at', 'published_at', 'requires_reconsent', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
            'requires_reconsent' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Current published+effective version per purpose. Cached briefly (the
     * consent middleware calls this on every web page view); the cache is
     * flushed whenever a document is saved.
     *
     * @return array<string, array{id:int,purpose:string,version:string,title:string,page_slug:?string,summary_of_changes:?string,requires_reconsent:bool,effective_at:?string}>
     */
    public static function currentByPurpose(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $current = [];

            static::query()
                ->whereNotNull('published_at')
                ->where(fn ($q) => $q->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                ->orderBy('effective_at')
                ->orderBy('id')
                ->get()
                ->each(function (self $doc) use (&$current) {
                    $current[$doc->purpose] = [
                        'id' => $doc->id,
                        'purpose' => $doc->purpose,
                        'version' => (string) $doc->version,
                        'title' => $doc->title,
                        'page_slug' => $doc->page_slug,
                        'summary_of_changes' => $doc->summary_of_changes,
                        'requires_reconsent' => (bool) $doc->requires_reconsent,
                        'effective_at' => $doc->effective_at?->toIso8601String(),
                    ];
                });

            return $current;
        });
    }

    public function pageUrl(): ?string
    {
        return $this->page_slug ? url('/'.ltrim($this->page_slug, '/')) : null;
    }
}
