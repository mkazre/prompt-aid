<?php

namespace App\Filament\Resources\HealthArticles\Pages;

use App\Filament\Resources\HealthArticles\HealthArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHealthArticles extends ListRecords
{
    protected static string $resource = HealthArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
