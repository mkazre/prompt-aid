<?php

namespace App\Filament\Resources\PageTemplates\Pages;

use App\Filament\Resources\PageTemplates\PageTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPageTemplate extends EditRecord
{
    protected static string $resource = PageTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
