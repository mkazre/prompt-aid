<?php

namespace App\Filament\Resources\RideRateCards\Pages;

use App\Filament\Resources\RideRateCards\RideRateCardResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRideRateCard extends EditRecord
{
    protected static string $resource = RideRateCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
