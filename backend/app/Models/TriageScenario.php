<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One patient request scenario per triage level: the ordered steps the
 * patient goes through after choosing RED / ORANGE / YELLOW / GREEN.
 * Read and written only through App\Services\Triage\ScenarioEngine
 * (validation, versioning, cache).
 */
class TriageScenario extends Model
{
    protected $fillable = ['level', 'steps', 'version', 'is_active', 'updated_by'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TriageScenarioVersion::class)->orderByDesc('version');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
