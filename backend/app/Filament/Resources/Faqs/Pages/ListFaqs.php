<?php

namespace App\Filament\Resources\Faqs\Pages;

use App\Filament\Resources\Faqs\FaqResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFaqs extends ListRecords
{
    protected static string $resource = FaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')->label('View /help')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->url(route('help'), shouldOpenInNewTab: true),
            CreateAction::make(),
        ];
    }
}
