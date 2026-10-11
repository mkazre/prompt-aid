<?php

namespace App\Filament\Resources\AppWidgets\Pages;

use App\Filament\Resources\AppWidgets\AppWidgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppWidgets extends ListRecords
{
    protected static string $resource = AppWidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New banner widget'),
        ];
    }
}
