<?php

namespace App\PageBuilder\Blocks\Layout;

use App\PageBuilder\Blocks\AbstractBlock;

class SectionBlock extends AbstractBlock
{
    public static function id(): string
    {
        return 'section';
    }

    public static function label(): string
    {
        return 'Section';
    }

    public static function group(): string
    {
        return 'Layout';
    }

    public static function icon(): string
    {
        return 'heroicon-o-rectangle-group';
    }

    public static function acceptsChildren(): bool
    {
        return true;
    }

    /**
     * Sections default to container width — most other blocks default to
     * full-width-of-parent (null styles), which BlockRegistry's caller
     * applies when a new block row is created.
     */
    public static function defaultStyles(): array
    {
        return ['layout' => ['width' => 'container']];
    }

    public function view(): string
    {
        return 'page-builder.blocks.section';
    }
}
