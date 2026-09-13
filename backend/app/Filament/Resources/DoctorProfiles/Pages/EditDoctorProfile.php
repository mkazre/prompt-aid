<?php

namespace App\Filament\Resources\DoctorProfiles\Pages;

use App\Filament\Resources\DoctorProfiles\DoctorProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDoctorProfile extends EditRecord
{
    protected static string $resource = DoctorProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
