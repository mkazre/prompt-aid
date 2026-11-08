<?php

namespace App\Filament\Pages;

use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Finance\Accounts;
use App\Services\Finance\PayoutAccounts;
use App\Services\Finance\Statements;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Provider earnings statement: doctors + clinic admins (/staff), pharmacies
 * (/vendor), partners (/partner). Strictly the logged-in provider's own
 * ledger account(s) (Accounts::forUser); never anyone else's.
 */
class MyEarnings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = 'My earnings';

    protected static ?string $title = 'My earnings';

    protected static ?string $slug = 'my-earnings';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.my-earnings';

    public string $period = 'month';

    public ?string $from = null;

    public ?string $to = null;

    public ?string $account = null;

    public array $bank = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User
            && in_array($u->role, [User::ROLE_DOCTOR, User::ROLE_CLINIC_ADMIN, User::ROLE_PHARMACY_ADMIN, User::ROLE_THIRD_PARTY], true)
            && Accounts::forUser($u) !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->account = Accounts::pick(Auth::user())['type'].':'.Accounts::pick(Auth::user())['id'];
        $this->fillBank();
    }

    protected function current(): array
    {
        $acct = Accounts::pick(Auth::user(), $this->account);
        abort_unless($acct, 403);

        return $acct;
    }

    protected function fillBank(): void
    {
        $a = $this->current();
        $p = PayoutAccounts::present(app(PayoutAccounts::class)->for($a['type'], $a['id']));
        $this->bank = [
            'account_holder' => $p['account_holder'] ?? '',
            'bank_name' => $p['bank_name'] ?? '',
            'branch_code' => $p['branch_code'] ?? '',
            'account_number' => '',
            'bank_account_type' => $p['bank_account_type'] ?? 'cheque',
        ];
    }

    public function updatedAccount(): void
    {
        $this->current();
        $this->fillBank();
    }

    public function saveBank(): void
    {
        $a = $this->current();
        try {
            app(PayoutAccounts::class)->submit($a['type'], $a['id'], $this->bank, Auth::user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($v, $k) => ['bank.'.$k => $v])->all());

            return;
        }
        $this->resetErrorBag();
        $this->fillBank();
        Notification::make()->title('Bank details saved')->body('An administrator will verify them before your next payout.')->success()->send();
    }

    protected function getViewData(): array
    {
        $a = $this->current();
        $d = app(Statements::class)->data($a['type'], $a['id'], $this->period, $this->from, $this->to);
        $q = array_filter(['account' => $a['type'].':'.$a['id'], 'period' => $this->period, 'from' => $this->from, 'to' => $this->to]);

        return [
            'd' => $d,
            'accounts' => Accounts::forUser(Auth::user()),
            'csvUrl' => route('finance.statement', ['format' => 'csv'] + $q),
            'pdfUrl' => route('finance.statement', ['format' => 'pdf'] + $q),
            'banks' => PayoutAccounts::BANKS,
            'bankTypes' => PayoutAccount::BANK_ACCOUNT_TYPES,
        ];
    }
}
