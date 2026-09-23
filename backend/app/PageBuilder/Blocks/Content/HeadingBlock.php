<?php

namespace App\PageBuilder\Blocks\Content;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class HeadingBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'heading';
    }

    public static function label(): string
    {
        return 'Heading';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function icon(): string
    {
        return 'heroicon-o-bars-3-bottom-left';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('text')->label('Text')->required(),
            Select::make('level')->label('Level')->options([1 => 'H1', 2 => 'H2', 3 => 'H3', 4 => 'H4'])->default(2)->required(),
        ];
    }

    public static function defaults(): array
    {
        return ['text' => 'Heading', 'level' => 2];
    }

    public function view(): string
    {
        return 'page-builder.blocks.heading';
    }
}
