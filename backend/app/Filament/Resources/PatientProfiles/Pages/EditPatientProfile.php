<?php

namespace App\Filament\Resources\PatientProfiles\Pages;

use App\Filament\Resources\PatientProfiles\PatientProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPatientProfile extends EditRecord
{
    // POPIA: logs each open of this record to data_access_logs.
    use \App\Filament\Concerns\LogsRecordAccess;

    protected static string $resource = PatientProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
