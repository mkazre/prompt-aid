<?php

namespace App\PageBuilder\Rendering;

use App\Models\Page;
use App\Models\PageBlock;
use App\PageBuilder\BlockRegistry;
use App\PageBuilder\Templates\QuerySource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\HtmlString;

/**
 * Renders a Page's block tree to HTML. Each block wraps in
 * `.pa-block.pa-block-{id}`; compiled per-block CSS is collected into one
 * `<style>` tag rendered once at the end.
 *
 * LoopBlock is the one special case: its children are an *item template*,
 * rendered once per row from QuerySource with `$item` bound so
 * `{{ item.* }}` tokens (resolved by TokenResolver) reach every descendant
 * until another LoopBlock is hit.
 */
class PageRenderer
{
    protected array $collectedCss = [];

    public function __construct(
        protected StyleCompiler $compiler,
        protected TokenResolver $tokens,
        protected QuerySource $querySource,
    ) {}

    /**
     * Render a page's top-level blocks (and their descendants). Cached per
     * page + entity + query string (loop filters/sort/page live in the
     * query string), invalidated whenever any block on the page changes.
     */
    public function render(Page $page, ?Model $entity = null): HtmlString
    {
        $blocks = $page->allBlocks()->get();

        $cacheKey = $this->cacheKey($page, $blocks, $entity);

        $html = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($blocks, $entity) {
            $this->collectedCss = [];
            $tree = $this->buildTree($blocks);
            $body = $this->renderBlocks($tree, $entity);
            $styles = $this->collectedCss ? '<style>'.implode('', $this->collectedCss).'</style>' : '';

            return $styles.$body;
        });

        return new HtmlString($html);
    }

    protected function cacheKey(Page $page, Collection $blocks, ?Model $entity): string
    {
        $maxUpdated = $blocks->max('updated_at')?->timestamp ?? 0;
        $entityKey = $entity ? get_class($entity).':'.$entity->getKey() : 'none';
        $queryKey = md5(Request::getQueryString() ?? '');

        return "page_render:{$page->id}:{$maxUpdated}:{$entityKey}:{$queryKey}";
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

    protected function renderBlocks(Collection $blocks, ?Model $entity, ?Model $item = null): string
    {
        return $blocks->map(fn (PageBlock $block) => $this->renderBlock($block, $entity, $item))->implode('');
    }

    protected function renderBlock(PageBlock $block, ?Model $entity, ?Model $item = null): string
    {
        $instance = BlockRegistry::make($block->type);

        if (! $instance || ! $instance->isVisible($block)) {
            return '';
        }

        $css = $this->compiler->compile((string) $block->id, $block->styles ?? []);
        if ($css) {
            $this->collectedCss[] = $css;
        }

        if ($instance::isLoop()) {
            return $this->wrap($block, $this->renderLoop($block, $instance, $entity));
        }

        $props = ($item || $entity)
            ? $this->tokens->resolveProps($block->props ?? [], $item, $entity)
            : ($block->props ?? []);

        $childrenHtml = $instance::acceptsChildren()
            ? $this->renderBlocks($block->childBlocks ?? collect(), $entity, $item)
            : '';

        $data = $instance->data($props, $entity, $item);
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

        return $this->wrap($block, $inner);
    }

    protected function wrap(PageBlock $block, string $inner): string
    {
        $classes = "pa-block pa-block-{$block->id} pa-block-type-{$block->type}";

        return "<div class=\"{$classes}\" data-block-id=\"{$block->id}\">{$inner}</div>";
    }

    protected function renderLoop(PageBlock $block, $instance, ?Model $entity): string
    {
        $config = $block->props ?? [];
        $results = $this->querySource->paginate($config, Request::query());

        $cols = match ($config['layout'] ?? 'grid-3') {
            'grid-2' => 2, 'grid-4' => 4, default => 3,
        };

        $rowsHtml = $results->getCollection()->map(
            fn (Model $item) => '<div class="pa-loop-item">'.$this->renderBlocks($block->childBlocks ?? collect(), $entity, $item).'</div>',
        )->implode('');

        $grid = $rowsHtml
            ? "<div class=\"pa-grid\" style=\"grid-template-columns:repeat({$cols},minmax(0,1fr));background:transparent;border:0\">{$rowsHtml}</div>"
            : '<p class="pa-muted">No results found.</p>';

        $pagination = $results->hasPages() ? (string) $results->onEachSide(1)->links('page-builder.pagination') : '';

        return $grid.$pagination;
    }
}
