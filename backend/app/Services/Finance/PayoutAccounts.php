<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/** Provider bank details: submit (provider) → verify (admin). */
class PayoutAccounts
{
    public const BANKS = [
        'ABSA', 'African Bank', 'Bidvest Bank', 'Capitec', 'Discovery Bank', 'FNB', 'Investec', 'Nedbank',
        'Old Mutual', 'Standard Bank', 'TymeBank', 'Other',
    ];

    public static function rules(bool $requireNumber = true): array
    {
        return [
            'account_holder' => ['required', 'string', 'min:2', 'max:150'],
            'bank_name' => ['required', 'string', 'max:100'],
            'branch_code' => ['required', 'regex:/^\s*\d[\d\s]{4,9}\s*$/'],
            'account_number' => [$requireNumber ? 'required' : 'nullable', 'regex:/^\s*\d[\d\s-]{5,19}\s*$/'],
            'bank_account_type' => ['required', 'in:'.implode(',', array_keys(PayoutAccount::BANK_ACCOUNT_TYPES))],
        ];
    }

    public function for(string $type, int $id): ?PayoutAccount
    {
        return PayoutAccount::query()->where('account_type', $type)->where('account_id', $id)->first();
    }

    /**
     * Provider submits / updates bank details. Any change clears verification.
     * Throws ValidationException on bad input.
     */
    public function submit(string $type, int $id, array $input, ?User $by): PayoutAccount
    {
        $existing = $this->for($type, $id);
        $data = Validator::make($input, static::rules(! $existing))->validate();
        $acct = $existing ?? new PayoutAccount(['account_type' => $type, 'account_id' => $id]);
        $acct->fill([
            'account_holder' => trim($data['account_holder']),
            'bank_name' => trim($data['bank_name']),
            'branch_code' => $data['branch_code'],
            'bank_account_type' => $data['bank_account_type'],
            'submitted_by' => $by?->id,
        ]);
        if (! empty($data['account_number'])) {
            $acct->account_number = $data['account_number'];
        }
        $changed = ! $acct->exists || $acct->isDirty(['account_holder', 'bank_name', 'branch_code', 'account_number', 'bank_account_type']);
        if ($changed) {
            $acct->verified_at = null;
            $acct->verified_by = null;
        }
        $acct->save();
        if ($changed) {
            $this->audit($by, 'finance.bank_details.submitted', $acct);
        }

        return $acct;
    }

    public function verify(PayoutAccount $acct, ?User $by, bool $verified = true): void
    {
        $acct->forceFill(['verified_at' => $verified ? now() : null, 'verified_by' => $verified ? $by?->id : null])->save();
        $this->audit($by, $verified ? 'finance.bank_details.verified' : 'finance.bank_details.unverified', $acct);
    }

    /** Safe array for pages / API (never the full account number). */
    public static function present(?PayoutAccount $a): ?array
    {
        return $a ? [
            'account_holder' => $a->account_holder,
            'bank_name' => $a->bank_name,
            'branch_code' => $a->branch_code,
            'account_number_masked' => $a->maskedNumber(),
            'bank_account_type' => $a->bank_account_type,
            'verified' => $a->isVerified(),
            'verified_at' => $a->verified_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ] : null;
    }

    protected function audit(?User $by, string $event, PayoutAccount $a): void
    {
        try {
            AuditLog::query()->create(['user_id' => $by?->id, 'event' => $event, 'auditable_type' => PayoutAccount::class, 'auditable_id' => $a->id,
                'old_values' => null, 'new_values' => ['account' => $a->account_type.':'.$a->account_id, 'bank' => $a->bank_name, 'last4' => $a->account_last4], 'ip' => request()?->ip()]);
        } catch (\Throwable) {
        }
    }
}
