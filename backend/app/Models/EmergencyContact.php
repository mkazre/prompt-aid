<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-configurable replacement for what used to be hardcoded phone
 * numbers scattered across emergency.blade.php, emergency-results.blade.php,
 * promptaid.js and contact.blade.php. One row has is_primary = true — the
 * big "call ambulance now" CTA; the rest are the secondary numbers list.
 */
class EmergencyContact extends Model
{
    protected $fillable = [
        'label', 'phone', 'tel_url', 'is_primary', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Only one contact should ever be the big "call ambulance now" CTA —
        // enforce it here rather than trusting every admin to remember to
        // untoggle the previous one.
        static::saving(function (self $contact): void {
            if ($contact->is_primary) {
                static::where('id', '!=', $contact->id ?? 0)->update(['is_primary' => false]);
            }
        });

        static::saved(fn () => Cache::forget('emergency-contacts'));
        static::deleted(fn () => Cache::forget('emergency-contacts'));
    }

    /** @return Collection<int, EmergencyContact> */
    public static function activeOrdered(): Collection
    {
        return Cache::remember(
            'emergency-contacts',
            now()->addHour(),
            fn () => static::query()->where('is_active', true)->orderBy('sort_order')->get()
        );
    }

    public static function primary(): ?self
    {
        return static::activeOrdered()->firstWhere('is_primary', true);
    }
}
