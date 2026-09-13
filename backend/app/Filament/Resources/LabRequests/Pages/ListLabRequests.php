<?php

namespace App\Filament\Resources\LabRequests\Pages;

use App\Filament\Resources\LabRequests\LabRequestResource;
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
}
