<?php

namespace App\Filament\Resources\DoctorProfiles\Pages;

use App\Filament\Resources\DoctorProfiles\DoctorProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDoctorProfile extends CreateRecord
{
    protected static string $resource = DoctorProfileResource::class;
}
