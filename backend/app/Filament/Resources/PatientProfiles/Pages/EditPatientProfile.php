<?php

namespace App\Filament\Resources\PatientProfiles\Pages;

use App\Filament\Resources\PatientProfiles\PatientProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPatientProfile extends EditRecord
{
    protected static string $resource = PatientProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
