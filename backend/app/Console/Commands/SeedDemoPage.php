<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\PageBlock;
use Illuminate\Console\Command;

/**
 * One-off verification helper for the page builder — creates (or resets) a
 * "page-builder-smoke-test" page with one of each Phase 4 block, so the
 * whole pipeline (registry, editor, renderer, style compiler) can be
 * checked end to end without needing a working local dev environment.
 * Safe to run repeatedly.
 */
class SeedDemoPage extends Command
{
    protected $signature = 'page-builder:seed-demo';

    protected $description = 'Create a demo page exercising every Phase 4 block, for smoke-testing the page builder';

    public function handle(): int
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => 'page-builder-smoke-test'],
            ['title' => 'Page Builder Smoke Test', 'kind' => 'page', 'status' => 'published'],
        );

        $page->allBlocks()->delete();

        $section = PageBlock::query()->create([
            'page_id' => $page->id,
            'sort' => 1,
            'type' => 'section',
            'props' => [],
            'styles' => ['layout' => ['width' => 'container'], 'space' => ['pt' => 60, 'pr' => 0, 'pb' => 20, 'pl' => 0, 'mt' => 0, 'mb' => 0]],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 1,
            'type' => 'heading',
            'props' => ['text' => 'Page builder smoke test', 'level' => 1],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 2,
            'type' => 'rich-text',
            'props' => ['html' => '<p>If you can read this styled with the new palette, the renderer and style compiler both work.</p>'],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 3,
            'type' => 'stats',
            'props' => ['items' => [
                ['value' => '1', 'label' => 'Registry', 'description' => 'block resolved'],
                ['value' => '2', 'label' => 'Renderer', 'description' => 'tree walked'],
                ['value' => '3', 'label' => 'Compiler', 'description' => 'styles scoped'],
            ]],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 4,
            'type' => 'icon-list',
            'props' => ['items' => [
                ['icon' => '✅', 'title' => 'Layout blocks', 'description' => 'Section, Columns, Spacer, Divider'],
                ['icon' => '✅', 'title' => 'Content blocks', 'description' => 'Heading, Rich Text, Image, Button, Stats, Icon List'],
            ]],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 5,
            'type' => 'button',
            'props' => ['text' => 'Signal button', 'url' => '#', 'variant' => 'primary'],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'sort' => 2,
            'type' => 'divider',
            'props' => [],
        ]);

        $this->info("Seeded page id={$page->id} — preview at /staff/preview/pages/{$page->id}");

        return self::SUCCESS;
    }
}
