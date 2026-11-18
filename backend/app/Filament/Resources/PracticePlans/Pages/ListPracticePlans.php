<?php

namespace App\Filament\Resources\PracticePlans\Pages;

use App\Filament\Resources\PracticePlans\PracticePlanResource;
use Filament\Resources\Pages\ListRecords;

class ListPracticePlans extends ListRecords
{
    protected static string $resource = PracticePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
