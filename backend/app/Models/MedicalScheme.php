<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalScheme extends Model
{
    use HasFactory;

    public const SUBMISSION_ELECTRONIC = 'electronic';

    public const SUBMISSION_PORTAL = 'portal';

    public const SUBMISSION_MANUAL = 'manual';

    protected $fillable = ['name', 'code', 'claims_endpoint', 'submission_mode', 'active'];

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
