<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** POPIA s22 security compromise register entry. */
class SecurityIncident extends Model
{
    use \App\Concerns\Auditable;

    public const STATUSES = [
        'open' => 'Open',
        'investigating' => 'Investigating',
        'contained' => 'Contained',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public const SEVERITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    protected $fillable = [
        'reference', 'occurred_at', 'discovered_at', 'title', 'description', 'data_affected',
        'users_affected_count', 'severity', 'status', 'regulator_notified_at',
        'users_notified_at', 'remediation', 'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'discovered_at' => 'datetime',
            'regulator_notified_at' => 'datetime',
            'users_notified_at' => 'datetime',
            'users_affected_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $incident) {
            if (! $incident->reference) {
                $incident->forceFill(['reference' => 'SI-'.now()->format('Y').'-'.str_pad((string) $incident->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
