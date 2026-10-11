<?php

namespace App\Filament\Resources\HealthTopics\Pages;

use App\Filament\Resources\HealthTopics\HealthTopicResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHealthTopics extends ListRecords
{
    protected static string $resource = HealthTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
