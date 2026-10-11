<?php

namespace App\Filament\Resources\HealthTopics\Pages;

use App\Filament\Resources\HealthTopics\HealthTopicResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHealthTopic extends EditRecord
{
    protected static string $resource = HealthTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
