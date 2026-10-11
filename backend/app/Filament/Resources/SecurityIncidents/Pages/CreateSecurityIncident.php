<?php

namespace App\Filament\Resources\SecurityIncidents\Pages;

use App\Filament\Resources\SecurityIncidents\SecurityIncidentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSecurityIncident extends CreateRecord
{
    protected static string $resource = SecurityIncidentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reported_by'] = Auth::id();

        return $data;
    }
}
