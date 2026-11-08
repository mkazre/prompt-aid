<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Running balance per ledger account (row-locked while posting). */
class LedgerBalance extends Model
{
    protected $fillable = ['account_type', 'account_id', 'balance'];

    protected function casts(): array
    {
        return ['account_id' => 'integer'];
    }

    public function accountLabel(): string
    {
        return \App\Services\Finance\Accounts::label($this->account_type, (int) $this->account_id);
    }
}
