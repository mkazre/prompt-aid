<?php

namespace App\PageBuilder\Blocks\Directory;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * The archive-template engine. Props hold the query definition (source
 * model, sort, perPage, grid layout) — see QuerySource for what each
 * `source` resolves to. This block's *children* are the item template:
 * ordinary blocks whose text props may contain {{ item.* }} tokens,
 * resolved once per row by PageRenderer + TokenResolver.
 */
class LoopBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'loop';
    }

    public static function label(): string
    {
        return 'Loop (Archive Listing)';
    }

    public static function group(): string
    {
        return 'Directory';
    }

    public static function icon(): string
    {
        return 'heroicon-o-rectangle-stack';
    }

    public static function acceptsChildren(): bool
    {
        return true;
    }

    public static function isLoop(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            Select::make('source')
                ->label('Data source')
                ->options([
                    'doctor' => 'Doctors', 'clinic' => 'Clinics', 'pharmacy' => 'Pharmacies',
                    'product' => 'Products', 'service' => 'Services', 'third_party' => 'Third Parties',
                ])
                ->required(),
            Select::make('sort')
                ->label('Default sort')
                ->options(['relevance' => 'Relevance', 'rating' => 'Rating', 'price_asc' => 'Price (low first)', 'soonest' => 'Newest'])
                ->default('relevance'),
            TextInput::make('perPage')->label('Per page')->numeric()->default(12)->required(),
            Select::make('layout')
                ->label('Grid columns')
                ->options(['grid-2' => '2 columns', 'grid-3' => '3 columns', 'grid-4' => '4 columns'])
                ->default('grid-3')
                ->required(),
        ];
    }

    public static function defaults(): array
    {
        return ['source' => 'doctor', 'sort' => 'relevance', 'perPage' => 12, 'layout' => 'grid-3'];
    }

    public function view(): string
    {
        return 'page-builder.blocks.loop';
    }
}
