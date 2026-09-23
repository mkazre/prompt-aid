<?php

namespace App\PageBuilder\Blocks\Layout;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\TextInput;

class SpacerBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'spacer';
    }

    public static function label(): string
    {
        return 'Spacer';
    }

    public static function group(): string
    {
        return 'Layout';
    }

    public static function icon(): string
    {
        return 'heroicon-o-arrows-up-down';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('height')->numeric()->default(40)->suffix('px')->required(),
        ];
    }

    public static function defaults(): array
    {
        return ['height' => 40];
    }

    public function view(): string
    {
        return 'page-builder.blocks.spacer';
    }
}
