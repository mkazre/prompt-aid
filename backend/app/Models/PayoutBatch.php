<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    public const STATUSES = ['pending' => 'Pending approval', 'approved' => 'Approved', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'reference', 'period_start', 'period_end', 'status', 'total_amount', 'payout_count', 'source',
        'created_by', 'approved_by', 'approved_at', 'exported_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'approved_at' => 'datetime',
            'exported_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
