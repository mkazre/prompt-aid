<?php

namespace App\Filament\Resources\MedicalSchemes\Pages;

use App\Filament\Resources\MedicalSchemes\MedicalSchemeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMedicalScheme extends EditRecord
{
    protected static string $resource = MedicalSchemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
