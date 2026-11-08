<?php

namespace App\Support;

use App\Models\Setting;

/** Finance settings (Staff → Finance → Finance settings). Stored in `settings`. */
class FinanceSettings
{
    public const DEFAULTS = [
        'finance_min_payout' => '100.00',      // rand; balances below this roll over to the next batch
        'finance_hold_days' => '7',            // days after capture before earnings can be paid out
        'finance_auto_batch' => '1',           // generate a payout batch automatically each week
        'finance_batch_weekday' => '1',        // ISO weekday for the automatic batch (1 = Monday)
        'finance_reference_prefix' => 'PROMPTAID', // beneficiary statement reference prefix
        'finance_require_verified_bank' => '1', // only pay accounts whose bank details an admin verified
    ];

    public static function str(string $key): string
    {
        return (string) Setting::get($key, self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key): int
    {
        return (int) self::str($key);
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::str($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    public static function minPayoutCents(): int
    {
        return max(0, Money::cents(self::str('finance_min_payout')));
    }

    public static function holdDays(): int
    {
        return max(0, self::int('finance_hold_days'));
    }
}
