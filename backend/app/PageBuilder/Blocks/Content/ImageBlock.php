<?php

namespace App\PageBuilder\Blocks\Content;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;

class ImageBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'image';
    }

    public static function label(): string
    {
        return 'Image';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function icon(): string
    {
        return 'heroicon-o-photo';
    }

    public static function schema(): array
    {
        return [
            FileUpload::make('src')->label('Image')->image()->disk('public')->directory('page-builder')->required(),
            TextInput::make('alt')->label('Alt text')->required(),
            TextInput::make('link')->label('Link URL (optional)')->url(),
        ];
    }

    public static function defaults(): array
    {
        return ['src' => null, 'alt' => '', 'link' => null];
    }

    public function view(): string
    {
        return 'page-builder.blocks.image';
    }
}
