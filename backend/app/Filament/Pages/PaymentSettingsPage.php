<?php

namespace App\Filament\Pages;

use App\Services\Payments\GatewayManager;
use App\Support\PaymentSettings as PS;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Staff → System → Payment settings (super admin only).
 * Gateways ship disabled; while none is enabled + configured the mock
 * gateway keeps checkout working. Secrets are encrypted and never echoed:
 * the form only says whether each one is configured; leave blank to keep.
 */
class PaymentSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Payment settings';

    protected static ?string $title = 'Payment settings';

    protected static ?string $slug = 'payment-settings';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.payment-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('payment-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = [];
        foreach (PS::DEFAULTS as $key => $default) {
            if (in_array($key, PS::SECRETS, true)) {
                $state[$key] = '';
            } elseif (str_starts_with($key, 'payments_methods_')) {
                $state[$key] = PS::allowedFor(substr($key, strlen('payments_methods_')));
            } elseif (in_array($default, ['0', '1'], true) && ! in_array($key, ['payments_intent_ttl_minutes', 'consult_medical_aid_copay', 'callback_missed_fee', 'callback_missed_min_attempts', 'callback_missed_min_minutes'], true)) {
                $state[$key] = PS::bool($key);
            } else {
                $state[$key] = PS::str($key);
            }
        }
        foreach (PS::GATEWAYS as $g) {
            $state['clear_'.$g] = false;
        }

        $this->form->fill($state);
    }

    protected static function secretInput(string $key, string $label): TextInput
    {
        return TextInput::make($key)->label($label)->password()->revealable(false)->autocomplete('new-password')
            ->maxLength(500)->dehydrated(true)
            ->placeholder(fn () => PS::hasSecret($key) ? 'Configured ✓ — leave blank to keep' : 'Not set')
            ->helperText(fn () => PS::hasSecret($key) ? 'Configured ✓ (stored encrypted, never shown).' : 'Not configured yet.');
    }

    protected static function status(string $gateway): string
    {
        $g = app(GatewayManager::class)->get($gateway);

        return $g && $g->isConfigured() ? 'Status: LIVE in checkout ✓' : 'Status: not active (disabled or credentials missing).';
    }

    public function form(Schema $schema): Schema
    {
        $gatewayOptions = ['payfast' => 'PayFast', 'paystack' => 'Paystack', 'ozow' => 'Ozow'];
        $base = rtrim(url('/'), '/');

        return $schema
            ->components([
                Text::make(fn () => app(GatewayManager::class)->mockActive()
                    ? 'No gateway is live yet, so checkout runs in TEST MODE (mock payments, no real money). Enable a gateway and enter its credentials to go live.'
                    : 'Live gateways: '.implode(', ', array_map(fn ($g) => $g->label(), app(GatewayManager::class)->configured())).'.'),
                Tabs::make('Gateways')->tabs([
                    Tab::make('PayFast')->schema([
                        Text::make(fn () => static::status('payfast')),
                        Grid::make(2)->schema([
                            Toggle::make('payfast_enabled')->label('Enable PayFast'),
                            Toggle::make('payfast_sandbox')->label('Sandbox (sandbox.payfast.co.za)'),
                            TextInput::make('payfast_merchant_id')->label('Merchant ID')->maxLength(30),
                            static::secretInput('payfast_merchant_key', 'Merchant key'),
                            static::secretInput('payfast_passphrase', 'Passphrase (required for Onsite + subscriptions)'),
                            Toggle::make('payfast_onsite')->label('Onsite modal (off = PayFast page inside the sheet)'),
                            Toggle::make('payfast_validate_ip')->label('Check ITN source is a PayFast host'),
                            Toggle::make('payfast_subscriptions_enabled')->label('Recurring subscriptions'),
                            Toggle::make('payfast_tokenise_cards')->label('Allow "save my card" (tokenisation) for shuttle fees'),
                            Toggle::make('clear_payfast')->label('Remove saved PayFast secrets'),
                        ]),
                        Text::make('ITN / notify URL (sent automatically with each payment; no dashboard setting needed): '.$base.'/api/payments/webhooks/payfast'),
                        Text::make('PayFast dashboard → Settings → Developer settings: set the same passphrase as above. Enable "Recurring billing" and "Ad hoc / tokenisation" under Subscriptions.'),
                    ]),
                    Tab::make('Paystack')->schema([
                        Text::make(fn () => static::status('paystack')),
                        Grid::make(2)->schema([
                            Toggle::make('paystack_enabled')->label('Enable Paystack'),
                            Toggle::make('paystack_validate_ip')->label('Only accept webhooks from Paystack IPs'),
                            TextInput::make('paystack_public_key')->label('Public key (pk_test_… / pk_live_…)')->maxLength(120),
                            static::secretInput('paystack_secret_key', 'Secret key (sk_test_… / sk_live_…)'),
                            Toggle::make('clear_paystack')->label('Remove saved Paystack secret'),
                        ]),
                        Text::make('Paystack dashboard → Settings → API Keys & Webhooks → Webhook URL: '.$base.'/api/payments/webhooks/paystack (test and live). Test vs live is decided by the keys.'),
                    ]),
                    Tab::make('Ozow')->schema([
                        Text::make(fn () => static::status('ozow')),
                        Grid::make(2)->schema([
                            Toggle::make('ozow_enabled')->label('Enable Ozow'),
                            Toggle::make('ozow_sandbox')->label('Test mode (IsTest=true)'),
                            TextInput::make('ozow_site_code')->label('Site code')->maxLength(50),
                            static::secretInput('ozow_private_key', 'Private key'),
                            static::secretInput('ozow_api_key', 'API key (status checks)'),
                            Toggle::make('clear_ozow')->label('Remove saved Ozow secrets'),
                        ]),
                        Text::make('Notify URL (sent with each payment): '.$base.'/api/payments/webhooks/ozow. Ozow refunds are done in the Ozow merchant dashboard; then use "Mark refunded" on the payment.'),
                    ]),
                    Tab::make('Upfront payments')->schema([
                        Section::make('On-demand consults (see a doctor now, video, call back)')
                            ->description('The patient pays before any doctor is alerted. The money is held and only finalised when the consult starts; no match / expiry / doctor no-show or doctor cancel = full automatic refund; patient cancel after accept = cancellation fee kept, rest refunded.')
                            ->schema([Grid::make(2)->schema([
                                Toggle::make('consult_upfront_payment')->label('Require payment before connecting'),
                                TextInput::make('consult_payment_window_minutes')->label('Minutes to complete payment')->numeric()->minValue(2)->maxValue(60)->required(),
                                Toggle::make('consult_medical_aid_enabled')->label('Offer "bill my medical aid" (patients with a scheme)'),
                                TextInput::make('consult_medical_aid_copay')->label('Medical-aid co-payment taken upfront (R)')->numeric()->minValue(0)->required()
                                    ->helperText('Credited on the invoice; the claim covers the rest. A scheme shortfall is shown to the patient minus this co-pay.'),
                            ])]),
                        Section::make('Missed call-back')
                            ->description('When the patient does not answer a call-back: the fee is kept ONLY if the doctor made at least the minimum attempts spread over at least the minimum minutes; otherwise everything is refunded. Capped at the consult price.')
                            ->schema([Grid::make(3)->schema([
                                TextInput::make('callback_missed_fee')->label('Missed call-back fee (R)')->numeric()->minValue(0)->required(),
                                TextInput::make('callback_missed_min_attempts')->label('Minimum call attempts')->numeric()->minValue(1)->maxValue(20)->required(),
                                TextInput::make('callback_missed_min_minutes')->label('Minimum minutes first → last attempt')->numeric()->minValue(0)->maxValue(120)->required(),
                            ])]),
                        Section::make('Booked appointments')
                            ->description('Default for all doctors; a clinic or doctor can override it on their profile.')
                            ->schema([Grid::make(2)->schema([
                                Select::make('appointment_payment_policy')->label('Payment at booking')->options(PS::POLICIES)->required(),
                                TextInput::make('appointment_deposit_percent')->label('Deposit %')->numeric()->minValue(1)->maxValue(100)->required(),
                                TextInput::make('appointment_free_cancel_hours')->label('Free cancellation up to (hours before)')->numeric()->minValue(0)->required(),
                                TextInput::make('appointment_payment_window_minutes')->label('Minutes to pay before the slot is released')->numeric()->minValue(5)->maxValue(1440)->required(),
                                TextInput::make('appointment_late_cancel_keep_percent')->label('Late cancellation: % of the payment kept')->numeric()->minValue(0)->maxValue(100)->required(),
                            ])]),
                        Section::make('Shuttle & pharmacy')->schema([Grid::make(2)->schema([
                            Toggle::make('ride_upfront_hold')->label('Shuttle: secure the quoted fare upfront'),
                            TextInput::make('ride_payment_window_minutes')->label('Shuttle: minutes to pay before the request lapses')->numeric()->minValue(2)->maxValue(60)->required(),
                            TextInput::make('ride_hold_buffer_percent')->label('Extra % held for route changes')->numeric()->minValue(0)->maxValue(100)->required(),
                            Toggle::make('order_auto_refund_on_cancel')->label('Refund paid pharmacy orders automatically when cancelled'),
                        ])]),
                    ]),
                    Tab::make('Routing')->schema([
                        Grid::make(2)->schema([
                            Select::make('payments_default_gateway_web')->label('Default method — website')->options($gatewayOptions)->required(),
                            Select::make('payments_default_gateway_app')->label('Default method — app')->options($gatewayOptions)->required(),
                        ]),
                        Section::make('Allowed methods per service')->description('Only enabled + configured gateways are ever shown. Subscriptions are PayFast only.')->schema([
                            Grid::make(2)->schema(collect(PS::SERVICES)->map(fn ($label, $svc) => CheckboxList::make('payments_methods_'.$svc)->label($label)
                                ->options($svc === 'subscription' ? ['payfast' => 'PayFast'] : $gatewayOptions))->values()->all()),
                        ]),
                        Grid::make(2)->schema([
                            Toggle::make('payments_mock_when_unconfigured')->label('Use test-mode payments while no gateway is live'),
                            TextInput::make('payments_intent_ttl_minutes')->label('Payment link lifetime (minutes)')->numeric()->minValue(5)->maxValue(1440)->required(),
                        ]),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        foreach (PS::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (in_array($key, PS::SECRETS, true)) {
                if (is_string($value) && trim($value) !== '') {
                    PS::setSecret($key, $value);
                }

                continue;
            }
            if (str_starts_with($key, 'payments_methods_')) {
                $value = implode(',', array_values(array_intersect(PS::GATEWAYS, (array) $value)));
            }
            PS::set($key, is_bool($value) ? $value : trim((string) ($value ?? '')));
        }
        foreach (PS::GATEWAYS as $g) {
            if (! empty($data['clear_'.$g])) {
                foreach (PS::SECRETS as $secret) {
                    if (str_starts_with($secret, $g.'_')) {
                        PS::setSecret($secret, null);
                    }
                }
            }
        }

        $this->mount();
        Notification::make()->title('Payment settings saved')->success()->send();
    }
}
