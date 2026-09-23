<?php

namespace App\PageBuilder\Blocks\Content;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;

class StatsBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'stats';
    }

    public static function label(): string
    {
        return 'Stats Strip';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function icon(): string
    {
        return 'heroicon-o-chart-bar';
    }

    public static function schema(): array
    {
        return [
            Repeater::make('items')
                ->label('Stats')
                ->schema([
                    TextInput::make('value')->required(),
                    TextInput::make('label')->required(),
                    TextInput::make('description'),
                ])
                ->columns(3)
                ->defaultItems(3)
                ->required(),
        ];
    }

    public static function defaults(): array
    {
        return [
            'items' => [
                ['value' => '12,000+', 'label' => 'Patients served', 'description' => ''],
                ['value' => '45', 'label' => 'Clinics', 'description' => ''],
                ['value' => '4.8/5', 'label' => 'Average rating', 'description' => ''],
            ],
        ];
    }

    public function view(): string
    {
        return 'page-builder.blocks.stats';
    }
}
