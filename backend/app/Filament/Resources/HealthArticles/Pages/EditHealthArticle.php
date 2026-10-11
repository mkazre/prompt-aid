<?php

namespace App\Filament\Resources\HealthArticles\Pages;

use App\Filament\Resources\HealthArticles\HealthArticleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHealthArticle extends EditRecord
{
    protected static string $resource = HealthArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
