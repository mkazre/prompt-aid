<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalScheme extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'claims_endpoint', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SchemeMembership::class);
    }
}
