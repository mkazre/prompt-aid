<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Resources\Appointments\AppointmentVideoAction::make(),
                \App\Filament\Resources\Appointments\AppointmentVideoAction::callback(),
            DeleteAction::make(),
        ];
    }
}
