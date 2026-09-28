<?php

use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * One-time backfill: every Service/Product previously tagged a category
 * one of two ways — the free-text `category` string, or the unused
 * singular `service_category_id` FK — neither of which the new
 * many-to-many checkbox UI reads. This attaches the equivalent
 * ServiceCategory row(s) via the new pivots so existing data isn't lost
 * once the form stops writing to either old column. Both old columns are
 * left in place; nothing here drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfill(Service::query()->cursor(), 'serviceCategories');
        $this->backfill(Product::query()->cursor(), 'serviceCategories');
    }

    /**
     * @param  \Illuminate\Support\LazyCollection<int, \App\Models\Service|\App\Models\Product>  $records
     */
    protected function backfill($records, string $relation): void
    {
        foreach ($records as $record) {
            $categoryIds = [];

            // The unused FK, if it happened to be set.
            if ($record->service_category_id) {
                $categoryIds[] = $record->service_category_id;
            }

            // The free-text field — matched case-insensitively against
            // existing ServiceCategory names, created if nothing matches.
            $name = trim((string) $record->category);
            if ($name !== '') {
                $categoryIds[] = $this->resolveCategoryId($name);
            }

            $categoryIds = array_unique($categoryIds);

            if ($categoryIds !== []) {
                $record->{$relation}()->syncWithoutDetaching($categoryIds);
            }
        }
    }

    protected function resolveCategoryId(string $name): int
    {
        $existing = ServiceCategory::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return ServiceCategory::query()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug(Str::slug($name)),
        ])->id;
    }

    protected function uniqueSlug(string $base): string
    {
        $slug = $base !== '' ? $base : 'category';
        $suffix = 1;

        while (ServiceCategory::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    public function down(): void
    {
        // Data-only migration — detaching would be indistinguishable from
        // pivots created by hand after the backfill ran, so this is a no-op.
    }
};
