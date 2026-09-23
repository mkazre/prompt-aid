<?php

namespace App\PageBuilder\Blocks\Layout;

use App\PageBuilder\Blocks\AbstractBlock;

class DividerBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'divider';
    }

    public static function label(): string
    {
        return 'Divider';
    }

    public static function group(): string
    {
        return 'Layout';
    }

    public static function icon(): string
    {
        return 'heroicon-o-minus';
    }

    public function view(): string
    {
        return 'page-builder.blocks.divider';
    }
}
