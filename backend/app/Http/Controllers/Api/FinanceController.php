<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\Accounts;
use App\Services\Finance\EarningsService;
use App\Services\Finance\PayoutAccounts;
use App\Services\Finance\Statements;
use Illuminate\Http\Request;

/**
 * Provider earnings for the app (ready for adoption; the app does not call these yet).
 * Every endpoint is scoped to the caller's own ledger account(s). A user with
 * several accounts (clinic admin of 2 clinics) passes ?account=clinic:12.
 *
 *   GET  /api/finance/earnings?period=today|week|last_week|month|last_month|year|custom&from=&to=&bucket=day|week|month
 *   GET  /api/finance/payouts
 *   GET  /api/finance/payout-account
 *   POST /api/finance/payout-account {account_holder, bank_name, branch_code, account_number, bank_account_type}
 */
class FinanceController extends Controller
{
    public function earnings(Request $request, Statements $statements)
    {
        $acct = $this->account($request);
        $d = $statements->data($acct['type'], $acct['id'], (string) $request->query('period', 'month'), $request->query('from'), $request->query('to'), $request->query('bucket'));
        unset($d['bank']);
        $d['accounts'] = array_map(fn ($a) => ['key' => $a['type'].':'.$a['id'], 'type' => $a['type'], 'id' => $a['id'], 'label' => $a['label']], Accounts::forUser($request->user()));

        return response()->json(['data' => $d]);
    }

    public function payouts(Request $request, EarningsService $earnings)
    {
        $acct = $this->account($request);

        return response()->json(['data' => $earnings->payoutHistory($acct['type'], $acct['id'], 100), 'account' => $acct['type'].':'.$acct['id']]);
    }

    public function payoutAccount(Request $request, PayoutAccounts $accounts)
    {
        $acct = $this->account($request);

        return response()->json(['data' => PayoutAccounts::present($accounts->for($acct['type'], $acct['id'])), 'account' => $acct['type'].':'.$acct['id'],
            'banks' => PayoutAccounts::BANKS, 'account_types' => \App\Models\PayoutAccount::BANK_ACCOUNT_TYPES]);
    }

    public function savePayoutAccount(Request $request, PayoutAccounts $accounts)
    {
        $acct = $this->account($request);
        $saved = $accounts->submit($acct['type'], $acct['id'], $request->only(['account_holder', 'bank_name', 'branch_code', 'account_number', 'bank_account_type']), $request->user());

        return response()->json(['data' => PayoutAccounts::present($saved), 'message' => 'Bank details saved. An administrator will verify them before your next payout.']);
    }

    /** @return array{type: string, id: int, label: string} */
    protected function account(Request $request): array
    {
        $key = $request->input('account');
        $acct = Accounts::pick($request->user(), $key ? (string) $key : null);
        abort_unless($acct, $key ? 404 : 403, $key ? 'Account not found.' : 'Earnings are only available to providers (doctors, clinics, drivers, pharmacies, partners).');

        return $acct;
    }
}
