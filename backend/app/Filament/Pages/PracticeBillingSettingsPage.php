<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Services\Billing\PracticeSubscription as Gate;
use App\Support\PracticeBillingSettings as S;
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

/** Staff → Finance → Practice billing settings. Super admin only; every save is audited. */
class PracticeBillingSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Practice billing settings';

    protected static ?string $title = 'Practice billing settings';

    protected static ?string $slug = 'practice-billing-settings';

    protected static ?int $navigationSort = 22;

    protected string $view = 'filament.pages.payment-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('practice-billing-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $text = ['practice_billing_unsubscribed', 'practice_billing_retry_hours', 'practice_billing_grace_days', 'practice_billing_trial_reminder_days'];
        $fill = [];
        foreach (array_keys(S::DEFAULTS) as $k) {
            $fill[$k] = in_array($k, $text, true) ? S::str($k) : S::bool($k);
        }
        $this->form->fill($fill);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Enforcement')->description('Default OFF so no practice is cut off at deploy. Switch on once practices have subscribed (or been given trials).')->schema([
                    Grid::make(2)->schema([
                        Toggle::make('practice_billing_enforced')->label('Enforce practice subscriptions')
                            ->helperText('When on, suspended / ended practices stop receiving what is gated below.'),
                        Select::make('practice_billing_unsubscribed')->label('Practices with no subscription at all')
                            ->options(['block' => 'Treat as lapsed (block)', 'allow' => 'Allow (grandfathered)'])->required(),
                        Toggle::make('practice_billing_gate_instant')->label('Gate instant / dispatched patient requests'),
                        Toggle::make('practice_billing_gate_listing')->label('Hide lapsed practices from search & directory'),
                        Toggle::make('practice_billing_gate_bookings')->label('Refuse new online bookings for lapsed practices'),
                    ]),
                ]),
                Section::make('Missed payments')->schema([
                    Grid::make(2)->schema([
                        TextInput::make('practice_billing_retry_hours')->label('"Payment failed" window (hours)')->numeric()->integer()->minValue(0)->maxValue(240)->required()
                            ->helperText('After a renewal is missed the practice is "past due" for this long (late bank / PayFast retries), then grace starts.'),
                        TextInput::make('practice_billing_grace_days')->label('Grace period (days)')->numeric()->integer()->minValue(0)->maxValue(60)->required()
                            ->helperText('Still receiving requests during grace; suspended afterwards.'),
                        Toggle::make('practice_billing_reminders')->label('Daily reminders while past due / in grace (in-app + email)'),
                        TextInput::make('practice_billing_trial_reminder_days')->label('Remind before a trial ends (days, 0 = off)')->numeric()->integer()->minValue(0)->maxValue(30)->required(),
                    ]),
                ]),
                Section::make('Testing')->schema([
                    Toggle::make('practice_billing_allow_simulation')->label('Allow "Simulate renewal" in production')
                        ->helperText('Always available outside production and while the mock (test) gateway is active.'),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();
        $old = [];
        $new = [];
        foreach (array_keys(S::DEFAULTS) as $k) {
            $v = $d[$k] ?? S::DEFAULTS[$k];
            $v = match ($k) {
                'practice_billing_unsubscribed' => $v === 'allow' ? 'allow' : 'block',
                'practice_billing_retry_hours' => (string) max(0, min(240, (int) $v)),
                'practice_billing_grace_days' => (string) max(0, min(60, (int) $v)),
                'practice_billing_trial_reminder_days' => (string) max(0, min(30, (int) $v)),
                default => $v ? '1' : '0',
            };
            if (S::str($k) !== $v) {
                $old[$k] = S::str($k);
                $new[$k] = $v;
            }
            S::set($k, $v);
        }
        if ($new) {
            AuditLog::query()->create(['user_id' => Auth::id(), 'event' => 'practice_billing.settings_saved', 'auditable_type' => 'settings', 'auditable_id' => 0,
                'old_values' => $old, 'new_values' => $new, 'ip' => request()->ip()]);
        }
        Gate::flush();
        Notification::make()->title('Practice billing settings saved')->success()->send();
        $this->mount();
    }
}
