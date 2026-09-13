<?php

namespace App\Filament\Resources\DriverProfiles\Pages;

use App\Filament\Resources\DriverProfiles\DriverProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDriverProfile extends EditRecord
{
    protected static string $resource = DriverProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
