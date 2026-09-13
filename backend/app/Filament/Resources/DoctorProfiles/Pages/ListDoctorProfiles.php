<?php

namespace App\Filament\Resources\DoctorProfiles\Pages;

use App\Filament\Resources\DoctorProfiles\DoctorProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDoctorProfiles extends ListRecords
{
    protected static string $resource = DoctorProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
