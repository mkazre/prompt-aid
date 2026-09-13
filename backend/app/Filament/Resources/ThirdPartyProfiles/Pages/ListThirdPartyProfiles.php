<?php

namespace App\Filament\Resources\ThirdPartyProfiles\Pages;

use App\Filament\Resources\ThirdPartyProfiles\ThirdPartyProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListThirdPartyProfiles extends ListRecords
{
    protected static string $resource = ThirdPartyProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
