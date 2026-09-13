<?php

namespace App\Filament\Resources\LabRequests\Pages;

use App\Filament\Resources\LabRequests\LabRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLabRequest extends EditRecord
{
    protected static string $resource = LabRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
