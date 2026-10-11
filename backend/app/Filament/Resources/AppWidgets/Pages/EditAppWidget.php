<?php

namespace App\Filament\Resources\AppWidgets\Pages;

use App\Filament\Resources\AppWidgets\AppWidgetResource;
use App\Support\Widgets\WidgetCatalog;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAppWidget extends EditRecord
{
    protected static string $resource = AppWidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Seeded widgets are switched off, not deleted, so they can be
            // switched back on without re-running the SQL.
            DeleteAction::make()->visible(fn () => ! WidgetCatalog::isSeeded($this->record->key)),
        ];
    }
}
