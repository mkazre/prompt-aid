<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable ledger line. Write only through App\Services\Finance\LedgerService. */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const ENTRY_TYPES = [
        'earning' => 'Earning',
        'commission' => 'Commission',
        'fee' => 'Patient fee',
        'refund_reversal' => 'Refund reversal',
        'adjustment' => 'Adjustment',
        'payout' => 'Payout',
        'cash_collected' => 'Cash collected',
    ];

    protected $fillable = [
        'account_type', 'account_id', 'source_type', 'source_id', 'service_type', 'entry_type', 'component',
        'group_key', 'amount', 'currency', 'balance_after', 'payout_id', 'meta', 'description',
        'idempotency_key', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'account_id' => 'integer',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accountLabel(): string
    {
        return \App\Services\Finance\Accounts::label($this->account_type, (int) $this->account_id);
    }
}
