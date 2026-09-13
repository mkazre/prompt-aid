<?php

namespace App\Filament\Resources\RideRateCards\Pages;

use App\Filament\Resources\RideRateCards\RideRateCardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRideRateCards extends ListRecords
{
    protected static string $resource = RideRateCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
