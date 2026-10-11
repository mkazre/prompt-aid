<?php

namespace App\Filament\Resources\SecurityIncidents\Pages;

use App\Filament\Resources\SecurityIncidents\SecurityIncidentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSecurityIncidents extends ListRecords
{
    protected static string $resource = SecurityIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Log incident')];
    }
}
