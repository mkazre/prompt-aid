<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A POPIA data subject request (s23 access, s24 correction/deletion,
 * s11(3) objection). due_at = received + privacy_dsr_sla_days (30).
 */
class DsrRequest extends Model
{
    use \App\Concerns\Auditable;

    public const TYPE_ACCESS = 'access';

    public const TYPE_CORRECTION = 'correction';

    public const TYPE_DELETION = 'deletion';

    public const TYPE_OBJECTION = 'objection';

    public const TYPES = [
        self::TYPE_ACCESS => 'Access (copy of my data)',
        self::TYPE_CORRECTION => 'Correction',
        self::TYPE_DELETION => 'Deletion',
        self::TYPE_OBJECTION => 'Objection to processing',
    ];

    public const STATUS_RECEIVED = 'received';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_RECEIVED => 'Received',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = [
        'user_id', 'type', 'status', 'details', 'channel', 'due_at', 'handled_by',
        'notes', 'retention_summary', 'export_path', 'export_size', 'export_expires_at',
        'anonymised_at', 'retain_clinical_until', 'fully_anonymised_at', 'completed_at',
    ];

    /** Never serialise the private storage path. */
    protected $hidden = ['export_path'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'export_expires_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'retain_clinical_until' => 'date',
            'fully_anonymised_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RECEIVED, self::STATUS_IN_PROGRESS]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_IN_PROGRESS], true);
    }

    public function hasDownloadableExport(): bool
    {
        return $this->type === self::TYPE_ACCESS
            && $this->export_path
            && (! $this->export_expires_at || $this->export_expires_at->isFuture());
    }

    /** ok | due_soon | overdue | closed — drives the SLA badge. */
    public function slaState(): string
    {
        if (! $this->isOpen()) {
            return 'closed';
        }
        if (! $this->due_at) {
            return 'ok';
        }
        if ($this->due_at->isPast()) {
            return 'overdue';
        }

        return $this->due_at->lte(now()->addDays(7)) ? 'due_soon' : 'ok';
    }
}
