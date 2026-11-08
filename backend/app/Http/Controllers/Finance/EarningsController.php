<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\Accounts;
use App\Services\Finance\PayoutAccounts;
use App\Services\Finance\Statements;
use Illuminate\Http\Request;

/**
 * Website side of provider earnings:
 *  - GET /finance/statement/{csv|pdf}?account=type:id&period=…  (any provider, own accounts only;
 *    super admins with ledger-entries.view may pass any account)
 *  - GET/POST /driver/earnings (+ /bank) — the driver web console's Earnings section
 */
class EarningsController extends Controller
{
    public function statement(Request $request, string $format, Statements $statements)
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $acct = $this->account($request);
        abort_unless($acct, 404);

        return $statements->download($format, $acct['type'], $acct['id'], $request->query('period'), $request->query('from'), $request->query('to'), $request->user());
    }

    public function driver(Request $request, Statements $statements)
    {
        $acct = Accounts::pick($request->user());
        abort_unless($acct && $acct['type'] === 'driver', 403);
        $period = (string) $request->query('period', 'month');
        $data = $statements->data('driver', $acct['id'], $period, $request->query('from'), $request->query('to'));

        return view('driver.earnings', ['d' => $data, 'banks' => PayoutAccounts::BANKS, 'bankTypes' => \App\Models\PayoutAccount::BANK_ACCOUNT_TYPES]);
    }

    public function driverBank(Request $request, PayoutAccounts $accounts)
    {
        $acct = Accounts::pick($request->user());
        abort_unless($acct && $acct['type'] === 'driver', 403);
        $accounts->submit('driver', $acct['id'], $request->only(['account_holder', 'bank_name', 'branch_code', 'account_number', 'bank_account_type']), $request->user());

        return redirect()->route('driver.earnings')->with('success', 'Bank details saved. An administrator will verify them before your next payout.');
    }

    protected function account(Request $request): ?array
    {
        $user = $request->user();
        $key = $request->query('account');
        $own = Accounts::pick($user, $key ? (string) $key : null);
        if ($own) {
            return $own;
        }
        if ($key && $user?->isSuperAdmin() && $user->hasPermission('ledger-entries.view') && preg_match('/^([a-z]+):(\d+)$/', (string) $key, $m)
            && in_array($m[1], Accounts::PROVIDER_TYPES, true)) {
            return ['type' => $m[1], 'id' => (int) $m[2], 'label' => Accounts::label($m[1], (int) $m[2])];
        }

        return null;
    }
}
