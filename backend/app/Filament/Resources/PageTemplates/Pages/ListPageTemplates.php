<?php

namespace App\Filament\Resources\PageTemplates\Pages;

use App\Filament\Resources\PageTemplates\PageTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPageTemplates extends ListRecords
{
    protected static string $resource = PageTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
