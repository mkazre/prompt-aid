<?php

namespace App\Filament\Concerns;

use App\Services\Privacy\AccessLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Add to a Filament View/Edit record page that shows special personal
 * (health) information. Logs one `view` row to data_access_logs when the
 * page is opened (Livewire trait hook — runs after the page's own mount(),
 * so $this->record is resolved). Only record opens are logged, never list
 * pages. Changes are already covered field-by-field by the Auditable trait
 * on these models (audit_logs), so saves aren't duplicated here. (A public
 * Livewire `call` hook was avoided on purpose: it would be callable from
 * the browser and let staff forge log rows.)
 *
 * Fails open: never breaks the page.
 */
trait LogsRecordAccess
{
    public function mountLogsRecordAccess(): void
    {
        $this->logRecordAccess('view');
    }

    protected function logRecordAccess(string $action): void
    {
        try {
            $record = property_exists($this, 'record') ? $this->record : null;
            if ($record instanceof Model) {
                app(AccessLogger::class)->log($record, $action, 'staff:'.class_basename(static::class));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
