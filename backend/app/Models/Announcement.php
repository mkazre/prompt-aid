<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed announcement: a coloured bar at the top of every website
 * page and a dismissible banner on the app's home screens.
 */
class Announcement extends Model
{
    public const AUDIENCES = [
        'everyone' => 'Everyone',
        'guests' => 'Guests (not signed in, website only)',
        'patients' => 'Patients',
        'doctors' => 'Doctors',
        'drivers' => 'Drivers',
    ];

    protected $fillable = [
        'title', 'message', 'link_label', 'link_url', 'color', 'text_color', 'audience',
        'starts_at', 'ends_at', 'is_dismissible', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_dismissible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** The audience keys that apply to a viewer (null = guest). */
    public static function audiencesFor(?User $user): array
    {
        if (! $user) {
            return ['everyone', 'guests'];
        }

        return match ($user->role) {
            User::ROLE_PATIENT => ['everyone', 'patients'],
            User::ROLE_DOCTOR => ['everyone', 'doctors'],
            User::ROLE_DRIVER => ['everyone', 'drivers'],
            default => ['everyone'],
        };
    }

    /** @return Collection<int, Announcement> */
    public static function liveFor(?User $user): Collection
    {
        try {
            return static::query()->live()
                ->whereIn('audience', static::audiencesFor($user))
                ->orderBy('sort')
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable $e) {
            // Never break a page render (e.g. before the migration ran).
            return new Collection;
        }
    }

    /** Hex colour safe to drop into a style attribute. */
    public function safeColor(): string
    {
        return self::cleanHex($this->color, '#1F4E7A');
    }

    public function safeTextColor(): string
    {
        return self::cleanHex($this->text_color, '#FFFFFF');
    }

    public static function cleanHex(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? $value : $fallback;
    }

    /** Only http(s) or site-relative links. */
    public function safeLink(): ?string
    {
        $url = trim((string) $this->link_url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    public function toApiArray(): array
    {
        $link = $this->safeLink();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'link_label' => $link ? ($this->link_label ?: 'Learn more') : null,
            'link_url' => $link && str_starts_with($link, '/') ? url($link) : $link,
            'color' => $this->safeColor(),
            'text_color' => $this->safeTextColor(),
            'is_dismissible' => $this->is_dismissible,
            'version' => $this->updated_at?->timestamp,
        ];
    }
}
