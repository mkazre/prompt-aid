<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\PageTemplate;
use Illuminate\Console\Command;

/**
 * One-off verification helper for Phase 5 (LoopBlock, QuerySource,
 * TokenResolver, TemplateResolver, the catch-all route) — seeds a real
 * published doctor archive + single template pair, reachable at
 * /doctor-directory-demo and /doctor-directory-demo/{id}. Safe to run
 * repeatedly.
 */
class SeedDemoTemplates extends Command
{
    protected $signature = 'page-builder:seed-templates';

    protected $description = 'Create a demo doctor archive + single template pair, for smoke-testing Phase 5';

    public function handle(): int
    {
        $this->seedArchive();
        $this->seedSingle();

        $this->info('Archive: /doctor-directory-demo');
        $this->info('Single (pick any real doctor id): /doctor-directory-demo/{id}');

        return self::SUCCESS;
    }

    protected function seedArchive(): void
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => 'doctor-directory-demo'],
            ['title' => 'Doctor Directory (demo)', 'kind' => 'archive', 'entity_type' => 'doctor', 'status' => 'published'],
        );
        $page->allBlocks()->delete();

        $section = PageBlock::query()->create([
            'page_id' => $page->id,
            'sort' => 1,
            'type' => 'section',
            'props' => [],
            'styles' => ['layout' => ['width' => 'container'], 'space' => ['pt' => 60, 'pr' => 0, 'pb' => 60, 'pl' => 0, 'mt' => 0, 'mb' => 0]],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 1,
            'type' => 'heading',
            'props' => ['text' => 'Find a Doctor', 'level' => 1],
        ]);

        $loop = PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 2,
            'type' => 'loop',
            'props' => ['source' => 'doctor', 'sort' => 'rating', 'perPage' => 9, 'layout' => 'grid-3'],
            'styles' => ['space' => ['pt' => 20, 'pr' => 0, 'pb' => 0, 'pl' => 0, 'mt' => 0, 'mb' => 0]],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $loop->id,
            'sort' => 1,
            'type' => 'heading',
            'props' => ['text' => '{{ item.user.name }}', 'level' => 4],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $loop->id,
            'sort' => 2,
            'type' => 'rich-text',
            'props' => ['html' => '<p>{{ item.specialization }} · {{ item.consultation_fee | money }}</p>'],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $loop->id,
            'sort' => 3,
            'type' => 'button',
            'props' => ['text' => 'View profile', 'url' => '/doctor-directory-demo/{{ item.id }}', 'variant' => 'ghost'],
        ]);

        PageTemplate::query()->updateOrCreate(
            ['kind' => 'archive', 'entity_type' => 'doctor', 'page_id' => $page->id],
            ['name' => 'Default doctor archive', 'is_default' => true, 'priority' => 0],
        );
    }

    protected function seedSingle(): void
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => 'doctor-single-demo'],
            ['title' => 'Doctor Profile (demo)', 'kind' => 'single', 'entity_type' => 'doctor', 'status' => 'published'],
        );
        $page->allBlocks()->delete();

        $section = PageBlock::query()->create([
            'page_id' => $page->id,
            'sort' => 1,
            'type' => 'section',
            'props' => [],
            'styles' => ['layout' => ['width' => 'container'], 'space' => ['pt' => 60, 'pr' => 0, 'pb' => 60, 'pl' => 0, 'mt' => 0, 'mb' => 0]],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 1,
            'type' => 'heading',
            'props' => ['text' => '{{ entity.user.name }}', 'level' => 1],
        ]);

        PageBlock::query()->create([
            'page_id' => $page->id,
            'parent_id' => $section->id,
            'sort' => 2,
            'type' => 'rich-text',
            'props' => ['html' => '<p>{{ entity.specialization }} · {{ entity.experience_years }} yrs experience · {{ entity.consultation_fee | money }}</p><p>{{ entity.bio }}</p>'],
        ]);

        PageTemplate::query()->updateOrCreate(
            ['kind' => 'single', 'entity_type' => 'doctor', 'page_id' => $page->id],
            ['name' => 'Default doctor single', 'is_default' => true, 'priority' => 0],
        );
    }
}
