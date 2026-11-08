<?php

namespace App\Filament\Pages;

use App\Support\FinanceSettings as FS;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Staff → Finance → Finance settings (payout rules). Super admin only. */
class FinanceSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-8-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Finance settings';

    protected static ?string $title = 'Finance settings';

    protected static ?string $slug = 'finance-settings';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.payment-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('finance-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill([
            'finance_min_payout' => FS::str('finance_min_payout'),
            'finance_hold_days' => FS::int('finance_hold_days'),
            'finance_auto_batch' => FS::bool('finance_auto_batch'),
            'finance_batch_weekday' => (string) (FS::int('finance_batch_weekday') ?: 1),
            'finance_reference_prefix' => FS::str('finance_reference_prefix'),
            'finance_require_verified_bank' => FS::bool('finance_require_verified_bank'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payouts')->description('Commission rates and patient fees are set under Finance → Commission & fees.')->schema([
                    Grid::make(2)->schema([
                        TextInput::make('finance_min_payout')->label('Minimum payout (R)')->numeric()->minValue(0)->step(0.01)->required()
                            ->helperText('Smaller balances roll over to the next batch.'),
                        TextInput::make('finance_hold_days')->label('Hold period (days after capture)')->numeric()->integer()->minValue(0)->maxValue(90)->required()
                            ->helperText('Protects against refunds / chargebacks before money is paid out.'),
                        Toggle::make('finance_auto_batch')->label('Generate a payout batch automatically every week'),
                        Select::make('finance_batch_weekday')->label('Batch day')->options([
                            '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday',
                        ])->required(),
                        TextInput::make('finance_reference_prefix')->label('Beneficiary statement reference prefix')->maxLength(12)->required()
                            ->helperText('Shown on the provider\'s bank statement, e.g. PROMPTAID P123.'),
                        Toggle::make('finance_require_verified_bank')->label('Only pay bank details an admin has verified'),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();
        FS::set('finance_min_payout', number_format(max(0, (float) $d['finance_min_payout']), 2, '.', ''));
        FS::set('finance_hold_days', (string) max(0, min(90, (int) $d['finance_hold_days'])));
        FS::set('finance_auto_batch', (bool) $d['finance_auto_batch']);
        FS::set('finance_batch_weekday', (string) max(1, min(7, (int) $d['finance_batch_weekday'])));
        FS::set('finance_reference_prefix', preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $d['finance_reference_prefix'])) ?: 'PROMPTAID');
        FS::set('finance_require_verified_bank', (bool) $d['finance_require_verified_bank']);
        Notification::make()->title('Finance settings saved')->success()->send();
        $this->mount();
    }
}
