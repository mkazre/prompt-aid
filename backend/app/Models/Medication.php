<?php

namespace App\Models;

use App\Support\HealthSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A medication the patient takes, with daily dose times (HH:MM, local
 * Africa/Johannesburg time). Dose reminders + the taken log live in
 * medication_doses.
 */
class Medication extends Model
{
    use \App\Concerns\Auditable;

    protected $fillable = [
        'patient_profile_id', 'name', 'dose', 'times', 'start_date', 'end_date', 'notes', 'reminders_enabled',
    ];

    protected function casts(): array
    {
        return [
            'times' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'reminders_enabled' => 'boolean',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function doses(): HasMany
    {
        return $this->hasMany(MedicationDose::class);
    }

    /** Active on a local calendar day (start ≤ day ≤ end). */
    public function scopeActiveOn(Builder $query, string $day): Builder
    {
        return $query->whereDate('start_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $day));
    }

    public function isActiveOn(string $day): bool
    {
        return $this->start_date->toDateString() <= $day && (! $this->end_date || $this->end_date->toDateString() >= $day);
    }

    /** @return array<int, string> sorted, unique HH:MM */
    public function doseTimes(): array
    {
        $times = collect($this->times ?? [])
            ->map(fn ($t) => substr(trim((string) $t), 0, 5))
            ->filter(fn ($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t))
            ->unique()->sort()->values()->all();

        return $times;
    }

    /**
     * Normalise user input ("8:00, 20:00" or an array) into sorted HH:MM.
     *
     * @return array<int, string>
     */
    public static function parseTimes(array|string|null $input): array
    {
        $parts = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input);

        return collect($parts)
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->map(function ($t) {
                if (! preg_match('/^(\d{1,2})[:h.]?(\d{2})$/', $t, $m)) {
                    return null;
                }
                $h = (int) $m[1];
                $min = (int) $m[2];

                return ($h <= 23 && $min <= 59) ? sprintf('%02d:%02d', $h, $min) : null;
            })
            ->filter()->unique()->sort()->values()->all();
    }

    /**
     * Today's doses for this medication with a status each:
     * taken | due | missed | upcoming.
     *
     * @return array<int, array{time: string, status: string, taken_at: ?string}>
     */
    public function dosesFor(CarbonInterface $localNow, ?\Illuminate\Support\Collection $logs = null): array
    {
        $day = $localNow->toDateString();
        if (! $this->isActiveOn($day)) {
            return [];
        }
        $logs ??= $this->doses()->whereDate('dose_date', $day)->get();
        $missedAfter = HealthSettings::int('health_medication_missed_after_minutes') ?: 60;
        $nowMinutes = $localNow->hour * 60 + $localNow->minute;

        $out = [];
        foreach ($this->doseTimes() as $time) {
            $log = $logs->first(fn ($l) => $l->dose_time === $time && $l->dose_date->toDateString() === $day);
            [$h, $m] = array_map('intval', explode(':', $time));
            $mins = $h * 60 + $m;
            $status = match (true) {
                (bool) $log?->taken_at => 'taken',
                $nowMinutes < $mins => 'upcoming',
                $nowMinutes - $mins <= $missedAfter => 'due',
                default => 'missed',
            };
            $out[] = [
                'time' => $time,
                'status' => $status,
                'taken_at' => $log?->taken_at?->copy()->setTimezone(HealthSettings::TIMEZONE)->format('H:i'),
            ];
        }

        return $out;
    }

    public function toApi(?CarbonInterface $localNow = null): array
    {
        $localNow ??= now(HealthSettings::TIMEZONE);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'dose' => $this->dose,
            'times' => $this->doseTimes(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notes' => $this->notes,
            'reminders_enabled' => (bool) $this->reminders_enabled,
            'active' => $this->isActiveOn($localNow->toDateString()),
            'today' => $this->dosesFor($localNow),
        ];
    }
}
