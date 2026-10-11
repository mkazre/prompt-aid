<?php

namespace App\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records created/updated/deleted events to audit_logs. Applied only to
 * models where "who changed this and when" has real compliance value
 * (users, payments, claims, invoices, clinical status changes) — not
 * blanket-applied to every model, which would just be noise nobody reads.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->recordAudit('created', null, $model->getAttributes()));

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            // High-churn, non-compliance fields (e.g. a driver's live GPS
            // ping every few seconds) listed in a model's $auditIgnore
            // would otherwise flood audit_logs.
            $changes = array_diff_key($changes, array_flip($model->auditIgnoredAttributes()));
            if (empty($changes)) {
                return;
            }
            $model->recordAudit('updated', array_intersect_key($model->getOriginal(), $changes), $changes);
        });

        static::deleted(fn ($model) => $model->recordAudit('deleted', $model->getAttributes(), null));
    }

    protected function recordAudit(string $event, ?array $old, ?array $new): void
    {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'old_values' => $this->redactAuditValues($old),
            'new_values' => $this->redactAuditValues($new),
            'ip' => Request::ip(),
        ]);
    }

    /** @return array<int, string> */
    public function auditIgnoredAttributes(): array
    {
        return property_exists($this, 'auditIgnore') ? (array) $this->auditIgnore : [];
    }

    protected function redactAuditValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $hidden = array_merge(['password', 'remember_token'], $this->auditRedact ?? []);

        foreach ($hidden as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
