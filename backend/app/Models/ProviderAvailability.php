<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per provider user (doctor, driver, pharmacy admin, third party):
 * available NOW (heartbeat-backed), LATER today (from-to), TOMORROW
 * (from-to), an optional weekly schedule, and the provider's max service
 * radius. Times are clinic-local (Africa/Johannesburg).
 */
class ProviderAvailability extends Model
{
    protected $table = 'provider_availability';

    public const TZ = 'Africa/Johannesburg';

    protected $fillable = [
        'user_id', 'role', 'now_on', 'last_seen_at', 'later_date', 'later_from', 'later_to',
        'tomorrow_date', 'tomorrow_from', 'tomorrow_to', 'weekly', 'max_radius_km', 'lat', 'lng', 'location_at',
    ];

    protected function casts(): array
    {
        return [
            'now_on' => 'boolean',
            'last_seen_at' => 'datetime',
            'later_date' => 'date',
            'tomorrow_date' => 'date',
            'weekly' => 'array',
            'location_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function localNow(): Carbon
    {
        return now()->setTimezone(self::TZ);
    }

    /**
     * Declared windows as UTC Carbon pairs. LATER is only valid on the day
     * it was set; TOMORROW becomes "today" once that date arrives.
     *
     * @return array<int, array{kind: string, from: Carbon, to: Carbon}>
     */
    public function windows(): array
    {
        $out = [];
        $today = self::localNow()->toDateString();
        $rows = [
            ['later', $this->later_date, $this->later_from, $this->later_to],
            ['tomorrow', $this->tomorrow_date, $this->tomorrow_from, $this->tomorrow_to],
        ];
        foreach ($rows as [$kind, $date, $from, $to]) {
            if (! $date || ! $from || ! $to) {
                continue;
            }
            $d = $date->toDateString();
            if ($d < $today) {
                continue;
            }
            $f = Carbon::parse($d.' '.$from, self::TZ);
            $t = Carbon::parse($d.' '.$to, self::TZ);
            if ($t->lte($f)) {
                $t->addDay();
            }
            $out[] = ['kind' => $kind, 'from' => $f->utc(), 'to' => $t->utc()];
        }
        foreach ((array) $this->weekly as $row) {
            if (! isset($row['weekday'], $row['start'], $row['end'])) {
                continue;
            }
            for ($i = 0; $i < 7; $i++) {
                $day = self::localNow()->startOfDay()->addDays($i);
                if ((int) $day->dayOfWeek !== (int) $row['weekday']) {
                    continue;
                }
                $f = Carbon::parse($day->toDateString().' '.$row['start'], self::TZ);
                $t = Carbon::parse($day->toDateString().' '.$row['end'], self::TZ);
                if ($t->gt($f)) {
                    $out[] = ['kind' => 'weekly', 'from' => $f->utc(), 'to' => $t->utc()];
                }
            }
        }

        return $out;
    }

    /** Does any declared window cover this moment (or overlap [from, to])? */
    public function covers(\DateTimeInterface $from, ?\DateTimeInterface $to = null): bool
    {
        $from = Carbon::instance($from);
        $to = $to ? Carbon::instance($to) : $from;
        foreach ($this->windows() as $w) {
            if ($from->lte($w['to']) && $to->gte($w['from'])) {
                return true;
            }
        }

        return false;
    }

    public function isOnlineNow(): bool
    {
        $stale = max(30, \App\Support\DispatchSettings::int('dispatch_heartbeat_stale_seconds'));

        return $this->now_on && $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subSeconds($stale));
    }
}
