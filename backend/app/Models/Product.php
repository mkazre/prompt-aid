<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    public const KIND_GOODS = 'goods';

    public const KIND_LAB_PACKAGE = 'lab_package';

    public const KIND_SERVICE_BLOCK = 'service_block';

    public const KIND_CONSULT_BUNDLE = 'consult_bundle';

    protected $fillable = [
        'pharmacy_id', 'service_category_id', 'kind', 'name', 'slug', 'category', 'description', 'image',
        'price', 'stock', 'requires_prescription', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_prescription' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function inStock(): bool
    {
        return $this->stock > 0;
    }
}
