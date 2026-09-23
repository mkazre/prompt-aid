<?php

namespace App\Filament\Resources\MedicalSchemes\Pages;

use App\Filament\Resources\MedicalSchemes\MedicalSchemeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMedicalSchemes extends ListRecords
{
    protected static string $resource = MedicalSchemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
