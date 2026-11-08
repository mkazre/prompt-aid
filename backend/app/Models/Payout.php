<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'payout_batch_id', 'account_type', 'account_id', 'payout_account_id', 'amount', 'period_start', 'period_end',
        'status', 'reference', 'bank_reference', 'paid_at', 'failed_reason', 'actioned_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
            'account_id' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    public function accountLabel(): string
    {
        return \App\Services\Finance\Accounts::label($this->account_type, (int) $this->account_id);
    }
}
