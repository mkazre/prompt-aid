<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrescription extends EditRecord
{
    // POPIA: logs each open of this record to data_access_logs.
    use \App\Filament\Concerns\LogsRecordAccess;

    protected static string $resource = PrescriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
