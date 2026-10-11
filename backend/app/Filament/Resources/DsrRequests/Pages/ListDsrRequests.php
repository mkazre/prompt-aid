<?php

namespace App\Filament\Resources\DsrRequests\Pages;

use App\Filament\Resources\DsrRequests\DsrRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDsrRequests extends ListRecords
{
    protected static string $resource = DsrRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Log a request received offline')];
    }
}
