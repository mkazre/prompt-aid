<?php

namespace App\Filament\Resources\Pharmacies\Pages;

use App\Filament\Resources\Pharmacies\PharmacyResource;
use App\Filament\Resources\Pharmacies\Widgets\PharmaciesHeaderStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPharmacies extends ListRecords
{
    protected static string $resource = PharmacyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PharmaciesHeaderStats::class,
        ];
    }
}
