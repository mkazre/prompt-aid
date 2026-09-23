<?php

namespace App\PageBuilder\Blocks\Layout;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\Select;

class ColumnsBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'columns';
    }

    public static function label(): string
    {
        return 'Columns';
    }

    public static function group(): string
    {
        return 'Layout';
    }

    public static function icon(): string
    {
        return 'heroicon-o-view-columns';
    }

    public static function acceptsChildren(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            Select::make('columns')
                ->label('Columns')
                ->options([2 => '2', 3 => '3', 4 => '4'])
                ->default(3)
                ->required(),
        ];
    }

    public static function defaults(): array
    {
        return ['columns' => 3];
    }

    public function view(): string
    {
        return 'page-builder.blocks.columns';
    }
}
