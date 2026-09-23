<?php

namespace App\Filament\Resources\PatientProfiles\Pages;

use App\Filament\Resources\PatientProfiles\PatientProfileResource;
use App\Filament\Resources\PatientProfiles\Widgets\PatientProfilesHeaderStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPatientProfiles extends ListRecords
{
    protected static string $resource = PatientProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PatientProfilesHeaderStats::class,
        ];
    }
}
