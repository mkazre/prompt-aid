<?php

namespace App\Filament\Resources\PatientProfiles\Pages;

use App\Filament\Resources\PatientProfiles\PatientProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePatientProfile extends CreateRecord
{
    protected static string $resource = PatientProfileResource::class;
}
