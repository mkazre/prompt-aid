<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable history row: every saved version of a triage scenario (who / when / what). */
class TriageScenarioVersion extends Model
{
    protected $fillable = ['triage_scenario_id', 'level', 'version', 'steps', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'version' => 'integer',
        ];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(TriageScenario::class, 'triage_scenario_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
