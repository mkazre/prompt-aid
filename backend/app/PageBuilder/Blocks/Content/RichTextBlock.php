<?php

namespace App\PageBuilder\Blocks\Content;

use App\PageBuilder\Blocks\AbstractBlock;
use Filament\Forms\Components\RichEditor;

class RichTextBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'rich-text';
    }

    public static function label(): string
    {
        return 'Rich Text';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function icon(): string
    {
        return 'heroicon-o-document-text';
    }

    public static function schema(): array
    {
        return [
            RichEditor::make('html')->label('Content')->required(),
        ];
    }

    public static function defaults(): array
    {
        return ['html' => '<p>Text goes here.</p>'];
    }

    public function view(): string
    {
        return 'page-builder.blocks.rich-text';
    }
}
