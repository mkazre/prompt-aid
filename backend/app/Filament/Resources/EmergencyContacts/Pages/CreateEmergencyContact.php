<?php

namespace App\Filament\Resources\EmergencyContacts\Pages;

use App\Filament\Resources\EmergencyContacts\EmergencyContactResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmergencyContact extends CreateRecord
{
    protected static string $resource = EmergencyContactResource::class;
}
