<?php

namespace App\Filament\Resources\ThirdPartyProfiles\Pages;

use App\Filament\Resources\ThirdPartyProfiles\ThirdPartyProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditThirdPartyProfile extends EditRecord
{
    protected static string $resource = ThirdPartyProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
