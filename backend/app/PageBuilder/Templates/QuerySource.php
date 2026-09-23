<?php

namespace App\PageBuilder\Templates;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\Service;
use App\Models\ThirdPartyProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a LoopBlock's declarative query definition (source + filters +
 * sort + perPage) into real paginated results. One entry per archive
 * `entity_type`; adding a new archivable model = adding one entry here.
 */
class QuerySource
{
    /**
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected const SOURCES = [
        'doctor' => DoctorProfile::class,
        'clinic' => Clinic::class,
        'pharmacy' => Pharmacy::class,
        'product' => Product::class,
        'service' => Service::class,
        'third_party' => ThirdPartyProfile::class,
    ];

    /**
     * @param  array<string, mixed>  $config  {source, filters, sort, perPage}
     * @param  array<string, mixed>  $requestFilters  query-string filter values keyed by field
     */
    public function paginate(array $config, array $requestFilters = []): LengthAwarePaginator
    {
        $source = $config['source'] ?? null;
        $modelClass = self::SOURCES[$source] ?? null;

        if (! $modelClass) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 1);
        }

        $query = $modelClass::query();
        $this->baseScope($source, $query);
        $this->withRelations($source, $query);

        foreach (($config['filters'] ?? []) as $filterDef) {
            $field = $filterDef['field'] ?? null;
            $value = $requestFilters[$field] ?? null;

            if ($field && $value !== null && $value !== '') {
                if (($filterDef['control'] ?? null) === 'multiselect' && is_array($value)) {
                    $query->whereIn($field, $value);
                } elseif (($filterDef['control'] ?? null) === 'toggle') {
                    $query->where($field, (bool) $value);
                } else {
                    $query->where($field, 'like', '%'.$value.'%');
                }
            }
        }

        $this->applySort($source, $query, $requestFilters['sort'] ?? ($config['sort'][0] ?? null));

        return $query->paginate($config['perPage'] ?? 12)->withQueryString();
    }

    protected function baseScope(string $source, Builder $query): void
    {
        match ($source) {
            'doctor', 'third_party' => $query->where('status', 'active'),
            'clinic', 'pharmacy' => $query->where('status', 'active'),
            'product', 'service' => $query->where('is_active', true),
            default => null,
        };
    }

    protected function withRelations(string $source, Builder $query): void
    {
        match ($source) {
            'doctor' => $query->with(['user', 'clinics']),
            'clinic' => $query->withCount('doctors'),
            'pharmacy' => $query->withCount('products'),
            'product' => $query->with('pharmacy'),
            'service' => $query->with(['clinic', 'doctor.user']),
            'third_party' => $query->with('user'),
            default => null,
        };
    }

    protected function applySort(string $source, Builder $query, ?string $sort): void
    {
        match ($sort) {
            'rating' => $query->orderByDesc('rating_avg'),
            'price_asc' => $query->orderBy(match ($source) {
                'doctor' => 'consultation_fee',
                'product' => 'price',
                default => 'id',
            }),
            'soonest' => $query->orderBy('created_at'),
            default => $query->latest(),
        };
    }
}
