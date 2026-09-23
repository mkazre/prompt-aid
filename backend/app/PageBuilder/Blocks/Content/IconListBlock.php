<?php

namespace App\PageBuilder\Blocks\Content;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class IconListBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'icon-list';
    }

    public static function label(): string
    {
        return 'Icon List';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function icon(): string
    {
        return 'heroicon-o-list-bullet';
    }

    public static function schema(): array
    {
        return [
            Repeater::make('items')
                ->label('Items')
                ->schema([
                    TextInput::make('icon')->label('Emoji / icon')->default('🩺'),
                    TextInput::make('title')->required(),
                    Textarea::make('description')->rows(2),
                ])
                ->defaultItems(3)
                ->required(),
        ];
    }

    public static function defaults(): array
    {
        return [
            'items' => [
                ['icon' => '🩺', 'title' => 'Book a doctor', 'description' => 'Find and book a verified doctor near you.'],
                ['icon' => '💊', 'title' => 'Order medication', 'description' => 'From licensed pharmacy partners.'],
                ['icon' => '🚐', 'title' => 'Get a ride', 'description' => 'Free patient shuttle to and from your visit.'],
            ],
        ];
    }

    public function view(): string
    {
        return 'page-builder.blocks.icon-list';
    }
}
