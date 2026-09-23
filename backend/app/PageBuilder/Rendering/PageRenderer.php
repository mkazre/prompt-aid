<?php

namespace App\PageBuilder\Rendering;

use App\Models\Page;
use App\Models\PageBlock;
use App\PageBuilder\BlockRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

/**
 * Renders a Page's block tree to HTML. Each block wraps in
 * `.pa-block.pa-block-{id}`; compiled per-block CSS is collected into one
 * `<style>` tag rendered once at the end via `renderStyles()`.
 */
class PageRenderer
{
    protected array $collectedCss = [];

    public function __construct(protected StyleCompiler $compiler) {}

    /**
     * Render a page's top-level blocks (and their descendants). Cached per
     * page + entity, invalidated whenever any block on the page changes
     * (the cache key includes the max block updated_at).
     */
    public function render(Page $page, ?Model $entity = null): HtmlString
    {
        $blocks = $page->allBlocks()->get();

        $cacheKey = $this->cacheKey($page, $blocks, $entity);

        $html = Cache::remember($cacheKey, now()->addHour(), function () use ($blocks, $entity) {
            $this->collectedCss = [];
            $tree = $this->buildTree($blocks);
            $body = $this->renderBlocks($tree, $entity);
            $styles = $this->collectedCss ? '<style>'.implode('', $this->collectedCss).'</style>' : '';

            return $styles.$body;
        });

        return new HtmlString($html);
    }

    /**
     * Render an explicit list of blocks (used by LoopBlock for its item
     * template, once per row) — not cached, since $entity differs per row.
     */
    public function renderBlockList(Collection $blocks, ?Model $entity = null): HtmlString
    {
        $tree = $this->buildTree($blocks);

        return new HtmlString($this->renderBlocks($tree, $entity));
    }

    protected function cacheKey(Page $page, Collection $blocks, ?Model $entity): string
    {
        $maxUpdated = $blocks->max('updated_at')?->timestamp ?? 0;
        $entityKey = $entity ? get_class($entity).':'.$entity->getKey() : 'none';

        return "page_render:{$page->id}:{$maxUpdated}:{$entityKey}";
    }

    /**
     * @param  Collection<int, PageBlock>  $blocks
     * @return Collection<int, PageBlock>  top-level blocks, each with ->childBlocks populated
     */
    protected function buildTree(Collection $blocks): Collection
    {
        $byParent = $blocks->groupBy('parent_id');

        $attach = function (PageBlock $block) use (&$attach, $byParent) {
            $block->setRelation(
                'childBlocks',
                ($byParent->get($block->id) ?? collect())->sortBy('sort')->values()->map($attach),
            );

            return $block;
        };

        return ($byParent->get(null) ?? collect())->sortBy('sort')->values()->map($attach);
    }

    protected function renderBlocks(Collection $blocks, ?Model $entity): string
    {
        return $blocks->map(fn (PageBlock $block) => $this->renderBlock($block, $entity))->implode('');
    }

    protected function renderBlock(PageBlock $block, ?Model $entity): string
    {
        $instance = BlockRegistry::make($block->type);

        if (! $instance || ! $instance->isVisible($block)) {
            return '';
        }

        $css = $this->compiler->compile((string) $block->id, $block->styles ?? []);
        if ($css) {
            $this->collectedCss[] = $css;
        }

        $childrenHtml = $instance::acceptsChildren()
            ? $this->renderBlocks($block->childBlocks ?? collect(), $entity)
            : '';

        $data = $instance->data($block->props ?? [], $entity);
        $data['children'] = new HtmlString($childrenHtml);
        $data['blockId'] = $block->id;

        try {
            $inner = view($instance->view(), $data)->render();
        } catch (\Throwable $e) {
            report($e);
            $inner = app()->environment('local')
                ? "<div style=\"padding:12px;background:#fee;color:#900;font:12px monospace\">Block render error ({$block->type}): ".e($e->getMessage()).'</div>'
                : '';
        }

        $classes = "pa-block pa-block-{$block->id} pa-block-type-{$block->type}";

        return "<div class=\"{$classes}\" data-block-id=\"{$block->id}\">{$inner}</div>";
    }
}
