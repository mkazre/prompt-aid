<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A provider's bank details. The account number is stored encrypted
 * (Laravel `encrypted` cast = Crypt with APP_KEY); only the last 4 digits
 * are kept in clear for display. Any change by the provider clears
 * verified_at (admin must re-verify before payouts resume).
 */
class PayoutAccount extends Model
{
    public const BANK_ACCOUNT_TYPES = ['cheque' => 'Cheque / current', 'savings' => 'Savings', 'transmission' => 'Transmission'];

    protected $fillable = [
        'account_type', 'account_id', 'account_holder', 'bank_name', 'branch_code', 'account_number',
        'account_last4', 'bank_account_type', 'verified_at', 'verified_by', 'submitted_by',
    ];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'verified_at' => 'datetime',
            'account_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if ($a->isDirty('account_number')) {
                $digits = preg_replace('/\D/', '', (string) $a->account_number);
                $a->account_number = $digits;
                $a->account_last4 = substr($digits, -4) ?: null;
            }
            if ($a->isDirty('branch_code')) {
                $a->branch_code = preg_replace('/\D/', '', (string) $a->branch_code);
            }
        });
    }

    public function maskedNumber(): string
    {
        return '•••• '.($this->account_last4 ?: '????');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function accountLabel(): string
    {
        return \App\Services\Finance\Accounts::label($this->account_type, (int) $this->account_id);
    }
}
