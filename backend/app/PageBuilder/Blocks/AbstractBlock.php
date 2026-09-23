<?php

namespace App\PageBuilder\Blocks;

use App\Models\PageBlock;
use Illuminate\Database\Eloquent\Model;

/**
 * A page builder block. Two files make a block: this class (id, label,
 * schema, defaults, the view it renders) and a Blade view under
 * resources/views/page-builder/blocks/{id}.blade.php. Auto-discovered by
 * BlockRegistry — nothing else to register.
 *
 * Every block shares the same fixed `styles` shape (see StyleCompiler) and
 * `visibility` shape ({"roles":[],"auth":"any|guest|user","devices":[]}) —
 * only `props` (the block's own content/behaviour) differs per block type,
 * shaped by that block's own schema().
 */
abstract class AbstractBlock
{
    abstract public static function id(): string;

    abstract public static function label(): string;

    abstract public static function group(): string;

    public static function icon(): string
    {
        return 'heroicon-o-squares-2x2';
    }

    /**
     * Filament form components for the editor's Content tab.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function schema(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [];
    }

    /**
     * Initial `styles` for a newly-added block. Most blocks want none
     * (full-width-of-parent, inherited colours); override for exceptions
     * like Section (container width by default).
     *
     * @return array<string, mixed>
     */
    public static function defaultStyles(): array
    {
        return [];
    }

    public static function acceptsChildren(): bool
    {
        return false;
    }

    abstract public function view(): string;

    /**
     * View data for rendering. $entity is the current single-template
     * entity (doctor/clinic/etc) when relevant, null otherwise; $item is
     * the current loop row when rendering inside a LoopBlock's item
     * template, null otherwise.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function data(array $props, ?Model $entity = null, ?Model $item = null): array
    {
        return ['props' => $props, 'entity' => $entity, 'item' => $item];
    }

    /**
     * Whether this block should render for the current request, given its
     * stored visibility rules and (when relevant) loop-item context.
     */
    public function isVisible(PageBlock $block): bool
    {
        $visibility = $block->visibility ?? [];

        $auth = $visibility['auth'] ?? 'any';
        $isAuthed = auth()->check();

        if ($auth === 'guest' && $isAuthed) {
            return false;
        }
        if ($auth === 'user' && ! $isAuthed) {
            return false;
        }

        $roles = $visibility['roles'] ?? [];
        if (! empty($roles) && (! $isAuthed || ! in_array(auth()->user()->role, $roles, true))) {
            return false;
        }

        return true;
    }
}
