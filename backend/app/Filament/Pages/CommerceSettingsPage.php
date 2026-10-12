<?php

namespace App\Filament\Pages;

use App\Support\CommerceSettings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Admin settings for promo codes, the referral programme, printable invoice
 * company details, doctor running-late / morning agenda alerts and
 * document expiry reminders. Stored in `settings` via CommerceSettings.
 */
class CommerceSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Commerce & alerts';

    protected static ?string $title = 'Commerce & alerts settings';

    protected static ?string $slug = 'commerce-settings';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.commerce-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('commerce-settings.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = [];
        foreach (CommerceSettings::DEFAULTS as $key => $default) {
            $state[$key] = in_array($default, ['0', '1'], true) && ! str_contains($key, 'number')
                ? CommerceSettings::bool($key)
                : CommerceSettings::str($key);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice details')
                    ->description('Printed on every invoice patients view or print (web, app and the staff Print action).')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('invoice_company_name')->label('Practice / company name')->required()->maxLength(150),
                            TextInput::make('invoice_company_email')->label('Billing email')->email()->maxLength(150),
                            TextInput::make('invoice_company_phone')->label('Billing phone')->maxLength(50),
                            TextInput::make('invoice_vat_number')->label('VAT number')->maxLength(50),
                            TextInput::make('invoice_registration_number')->label('Company registration number')->maxLength(50),
                            TextInput::make('invoice_practice_number')->label('Practice number (BHF)')->maxLength(50),
                        ]),
                        Textarea::make('invoice_company_address')->label('Address')->rows(3)->maxLength(500),
                        Grid::make(2)->schema([
                            TextInput::make('invoice_bank_name')->label('Bank')->maxLength(100),
                            TextInput::make('invoice_bank_account_name')->label('Account name')->maxLength(150),
                            TextInput::make('invoice_bank_account_number')->label('Account number')->maxLength(50),
                            TextInput::make('invoice_bank_branch_code')->label('Branch code')->maxLength(20),
                        ]),
                        Textarea::make('invoice_footer_note')->label('Footer note')->rows(2)->maxLength(500),
                        Toggle::make('invoice_auto_print')->label('Open the print dialog automatically when an invoice is opened'),
                    ]),
                Section::make('Promo codes')
                    ->description('Codes themselves are managed under Marketplace → Promo codes.')
                    ->schema([
                        Toggle::make('promo_codes_enabled')->label('Patients can apply promo codes at pharmacy checkout'),
                    ]),
                Section::make('Referral programme')
                    ->description('Every user gets a personal invite code. There are no payouts; the text below tells users what the programme is about.')
                    ->schema([
                        Toggle::make('referrals_enabled')->label('Referral programme enabled'),
                        Textarea::make('referral_reward_text')->label('Reward / programme text shown to users')->rows(3)->maxLength(1000),
                        Textarea::make('referral_share_message')->label('Share message')->rows(2)->maxLength(500)
                            ->helperText('Placeholders: {code}, {link}, {name}.'),
                    ]),
                Section::make('Doctor alerts')
                    ->schema([
                        Toggle::make('running_late_enabled')->label('Doctors can send "Running late" alerts to today\'s patients'),
                        Toggle::make('doctor_agenda_enabled')->label('Morning agenda alert to doctors with appointments today'),
                        Grid::make(2)->schema([
                            TextInput::make('doctor_agenda_time')->label('Send at (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required(),
                            TextInput::make('doctor_agenda_timezone')->label('Timezone')->required()->maxLength(64)
                                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                    if (! in_array($value, timezone_identifiers_list(), true)) {
                                        $fail('Use a valid timezone, e.g. Africa/Johannesburg.');
                                    }
                                }),
                        ]),
                    ]),
                Section::make('Document expiry reminders')
                    ->description('Driver and provider documents with an expiry date: the owner and the admins are alerted 30, 7 and 0 days before expiry (once each).')
                    ->schema([
                        Toggle::make('doc_expiry_enabled')->label('Send expiry reminders'),
                        Toggle::make('doc_expiry_notify_admins')->label('Also alert super admins'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        foreach (array_keys(CommerceSettings::DEFAULTS) as $key) {
            if (array_key_exists($key, $data)) {
                CommerceSettings::set($key, is_bool($data[$key]) ? $data[$key] : trim((string) ($data[$key] ?? '')));
            }
        }

        Notification::make()->title('Settings saved')->success()->send();
    }
}
