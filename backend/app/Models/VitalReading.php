<?php

namespace App\Models;

use App\Support\HealthSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One self-recorded set of vitals (any subset of the metrics). */
class VitalReading extends Model
{
    use \App\Concerns\Auditable;

    public const METRICS = ['systolic', 'diastolic', 'pulse', 'glucose', 'weight', 'temperature', 'spo2'];

    protected $fillable = [
        'patient_profile_id', 'recorded_by_user_id', 'recorded_at', 'systolic', 'diastolic', 'pulse',
        'glucose', 'weight', 'temperature', 'spo2', 'note',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'systolic' => 'integer',
            'diastolic' => 'integer',
            'pulse' => 'integer',
            'glucose' => 'float',
            'weight' => 'float',
            'temperature' => 'float',
            'spo2' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    /** @return array<string, string> metric => orange|red, only flagged metrics */
    public function flags(?array $ranges = null): array
    {
        $ranges ??= HealthSettings::vitalRanges();
        $flags = [];
        foreach (array_keys(HealthSettings::VITALS) as $metric) {
            if ($flag = HealthSettings::vitalFlag($metric, $this->{$metric}, $ranges)) {
                $flags[$metric] = $flag;
            }
        }

        return $flags;
    }

    public function worstFlag(?array $ranges = null): ?string
    {
        $flags = $this->flags($ranges);

        return in_array('red', $flags, true) ? 'red' : (in_array('orange', $flags, true) ? 'orange' : null);
    }

    public function toApi(?array $ranges = null): array
    {
        $out = ['id' => $this->id, 'recorded_at' => $this->recorded_at?->copy()->setTimezone(HealthSettings::TIMEZONE)->format('Y-m-d\TH:i:sP')];
        foreach (self::METRICS as $m) {
            $out[$m] = $this->{$m};
        }
        $out['note'] = $this->note;
        $out['flags'] = (object) $this->flags($ranges);

        return $out;
    }
}
