<?php

namespace App\Filament\Resources\LabRequests\Pages;

use App\Filament\Resources\LabRequests\LabRequestResource;
use App\Filament\Resources\LabRequests\Widgets\LabRequestsHeaderStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabRequests extends ListRecords
{
    protected static string $resource = LabRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            LabRequestsHeaderStats::class,
        ];
    }
}
