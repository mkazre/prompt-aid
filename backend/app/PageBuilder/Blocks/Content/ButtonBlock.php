<?php

namespace App\PageBuilder\Blocks\Content;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class ButtonBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'button';
    }

    public static function label(): string
    {
        return 'Button';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function icon(): string
    {
        return 'heroicon-o-cursor-arrow-rays';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('text')->label('Text')->required(),
            TextInput::make('url')->label('URL')->required(),
            Select::make('variant')->label('Style')->options([
                'primary' => 'Signal (filled)',
                'ghost' => 'Ghost (outline)',
                'quiet' => 'Quiet (dark)',
                'beacon' => 'Beacon (yellow)',
            ])->default('primary')->required(),
        ];
    }

    public static function defaults(): array
    {
        return ['text' => 'Learn more', 'url' => '#', 'variant' => 'primary'];
    }

    public function view(): string
    {
        return 'page-builder.blocks.button';
    }
}
