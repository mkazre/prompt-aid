<?php

namespace App\PageBuilder;

use App\PageBuilder\Blocks\AbstractBlock;
use Illuminate\Support\Facades\File;

/**
 * Discovers every AbstractBlock subclass under app/PageBuilder/Blocks and
 * resolves them by id. Adding a block = adding two files (class + Blade
 * view) — this class finds it automatically, mirroring how Filament
 * discovers resources.
 */
class BlockRegistry
{
    /** @var array<string, class-string<AbstractBlock>>|null */
    protected static ?array $blocks = null;

    /**
     * @return array<string, class-string<AbstractBlock>>
     */
    public static function all(): array
    {
        if (static::$blocks !== null) {
            return static::$blocks;
        }

        $blocks = [];
        $baseDir = app_path('PageBuilder/Blocks');

        if (! File::isDirectory($baseDir)) {
            return static::$blocks = [];
        }

        foreach (File::allFiles($baseDir) as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\PageBuilder\\Blocks\\'.$relative;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(AbstractBlock::class)) {
                continue;
            }

            /** @var class-string<AbstractBlock> $class */
            $blocks[$class::id()] = $class;
        }

        return static::$blocks = $blocks;
    }

    /**
     * @return class-string<AbstractBlock>|null
     */
    public static function resolve(string $id): ?string
    {
        return static::all()[$id] ?? null;
    }

    public static function make(string $id): ?AbstractBlock
    {
        $class = static::resolve($id);

        return $class ? new $class : null;
    }

    /**
     * Blocks grouped for the editor's add-block palette.
     *
     * @return array<string, array<int, array{id: string, label: string, icon: string}>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (static::all() as $id => $class) {
            $groups[$class::group()][] = [
                'id' => $id,
                'label' => $class::label(),
                'icon' => $class::icon(),
            ];
        }

        return $groups;
    }

    public static function flush(): void
    {
        static::$blocks = null;
    }
}
