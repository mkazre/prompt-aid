<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->whereNull('parent_id')->orderBy('sort');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort');
    }

    public static function tree(string $key): array
    {
        $menu = static::query()->where('key', $key)->with('allItems')->first();

        if (! $menu) {
            return [];
        }

        $items = $menu->allItems;

        $build = function ($parentId) use (&$build, $items) {
            return $items->where('parent_id', $parentId)->map(fn (MenuItem $item) => [
                'label' => $item->label,
                'url' => $item->url,
                'route' => $item->route,
                'target' => $item->target,
                'children' => $build($item->id),
            ])->values()->all();
        };

        return $build(null);
    }
}
