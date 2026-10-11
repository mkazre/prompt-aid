<?php

namespace App\Filament\Resources\SecurityIncidents\Pages;

use App\Filament\Resources\SecurityIncidents\SecurityIncidentResource;
use Filament\Resources\Pages\EditRecord;

/** No delete action: the breach register is compliance evidence. */
class EditSecurityIncident extends EditRecord
{
    protected static string $resource = SecurityIncidentResource::class;
}
