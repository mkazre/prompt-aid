<?php

namespace App\Filament\Resources\PracticeSubscriptions;

use App\Filament\Resources\PracticeSubscriptions\Pages\ListPracticeSubscriptions;
use App\Filament\Resources\PracticeSubscriptions\Pages\ViewPracticeSubscription;
use App\Filament\Resources\PracticeSubscriptions\RelationManagers\EventsRelationManager;
use App\Filament\Resources\PracticeSubscriptions\RelationManagers\InvoicesRelationManager;
use App\Models\PracticePlan;
use App\Models\PracticeSubscription;
use App\Services\Billing\PracticeBilling;
use App\Services\Billing\PracticeSubscription as Gate;
use App\Support\Money;
use App\Support\PracticeBillingSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Staff → Finance → Practice subscriptions. Every admin override (waive,
 * extend trial, change plan, mark paid, suspend, reactivate, cancel) is
 * written to the practice's billing timeline AND the audit log.
 */
class PracticeSubscriptionResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = true; // rows are created by the billing flow / admin actions, never the generic form

    protected static function permissionKey(): string
    {
        return 'practice-subscriptions';
    }

    protected static ?string $model = PracticeSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Practice subscriptions';

    protected static ?string $modelLabel = 'practice subscription';

    protected static ?int $navigationSort = 21;

    public static function canEditActions(): bool
    {
        return static::allowed('edit');
    }

    public static function infolist(Schema $schema): Schema
    {
        $date = fn ($state) => $state ? Carbon::parse($state)->format('d M Y H:i') : '—';

        return $schema->components([
            Section::make('Practice subscription')->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    TextEntry::make('practice')->label('Practice')->state(fn (PracticeSubscription $r) => $r->subscriberLabel()),
                    TextEntry::make('plan.name')->label('Plan')->placeholder('—'),
                    TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => PracticeSubscription::STATUSES[$state] ?? $state)
                        ->color(fn ($state) => PracticeSubscription::STATUS_COLORS[$state] ?? 'gray'),
                    TextEntry::make('amount_cents')->label('Recurring amount')->formatStateUsing(fn ($state, PracticeSubscription $r) => $r->amountLabel()),
                    TextEntry::make('seats')->label('Billed doctor seats'),
                    TextEntry::make('gate')->label('Receives requests now')->state(function (PracticeSubscription $r) {
                        $p = $r->subscriber();
                        if (! $p) {
                            return '—';
                        }

                        return (Gate::canReceiveRequests($p) ? 'Yes' : 'No').(PracticeBillingSettings::enforced() ? '' : ' (enforcement off)');
                    }),
                    TextEntry::make('gateway')->badge()->placeholder('—'),
                    TextEntry::make('card')->label('Card agreement')->state(fn (PracticeSubscription $r) => $r->token ? 'PayFast token on file' : 'None'),
                    TextEntry::make('trial_ends_at')->label('Trial ends')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('current_period_start')->label('Period start')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('current_period_end')->label('Paid until')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('next_billing_at')->label('Next billing')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('past_due_at')->label('Past due since')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('grace_ends_at')->label('Grace ends')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('suspended_at')->label('Suspended at')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('comped_until')->label('Waived until')->formatStateUsing($date)->placeholder('—'),
                    TextEntry::make('comp_reason')->label('Waiver reason')->placeholder('—'),
                    TextEntry::make('cancel_reason')->label('Cancellation')->state(fn (PracticeSubscription $r) => $r->cancel_at_period_end ? 'Ends at period end — '.$r->cancel_reason : ($r->cancel_reason ?: '—')),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('subscriber_id')->label('Practice')->formatStateUsing(fn ($state, PracticeSubscription $r) => $r->subscriberLabel())
                    ->description(fn (PracticeSubscription $r) => $r->subscriber_type === 'clinic' ? 'Clinic' : 'Independent doctor'),
                TextColumn::make('plan.name')->label('Plan')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => PracticeSubscription::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => PracticeSubscription::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('amount_cents')->label('Amount')->formatStateUsing(fn ($state, PracticeSubscription $r) => $r->amountLabel()),
                TextColumn::make('seats')->label('Seats'),
                TextColumn::make('current_period_end')->label('Paid until')->date('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('trial_ends_at')->label('Trial ends')->date('d M Y')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('gateway')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('comped_until')->label('Waived until')->date('d M Y')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(PracticeSubscription::STATUSES),
                SelectFilter::make('subscriber_type')->label('Type')->options(['clinic' => 'Clinic', 'doctor' => 'Independent doctor']),
                SelectFilter::make('practice_plan_id')->label('Plan')->relationship('plan', 'name'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return array<int, Action> admin overrides for the view page */
    public static function adminActions(): array
    {
        $by = fn () => Auth::user();
        $svc = fn () => app(PracticeBilling::class);
        $run = function (callable $fn, string $done) {
            try {
                $fn();
                Notification::make()->title($done)->success()->send();
            } catch (\Throwable $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
        $can = fn () => static::allowed('edit');
        $planOptions = fn (PracticeSubscription $r) => PracticePlan::query()->for((string) $r->subscriber_type)->orderBy('sort_order')->pluck('name', 'id')->all();

        return [
            Action::make('changePlan')->label('Change plan')->icon('heroicon-o-arrows-right-left')->visible($can)
                ->schema(fn (PracticeSubscription $r) => [
                    Select::make('plan')->options($planOptions($r))->default($r->practice_plan_id)->required(),
                    Select::make('cycle')->options(PracticeSubscription::CYCLES)->default($r->billing_cycle)->required(),
                ])
                ->action(fn (PracticeSubscription $r, array $data) => $run(fn () => $svc()->changePlan($r, PracticePlan::query()->findOrFail($data['plan']), $data['cycle'], $by(), true), 'Plan changed')),

            Action::make('extendTrial')->label('Extend trial')->icon('heroicon-o-clock')->visible(fn (PracticeSubscription $r) => $can() && ($r->status === 'trialing' || ! $r->last_paid_at))
                ->schema([TextInput::make('days')->numeric()->integer()->minValue(1)->maxValue(365)->default(14)->required()])
                ->action(fn (PracticeSubscription $r, array $data) => $run(fn () => $svc()->extendTrial($r, (int) $data['days'], $by()), 'Trial extended')),

            Action::make('comp')->label('Waive (comp)')->icon('heroicon-o-gift')->visible($can)
                ->schema([
                    DatePicker::make('until')->label('Free until')->required()->minDate(now()->addDay())->default(now()->addMonth()),
                    TextInput::make('reason')->required()->maxLength(200),
                ])
                ->modalDescription('The practice is covered (receives requests) until this date and billing resumes afterwards. For PayFast card agreements the next debit is moved to this date.')
                ->action(fn (PracticeSubscription $r, array $data) => $run(fn () => $svc()->comp($r, Carbon::parse($data['until'])->endOfDay(), $data['reason'], $by()), 'Waiver saved')),

            Action::make('markPaid')->label('Mark paid (EFT)')->icon('heroicon-o-banknotes')->visible($can)
                ->schema(fn (PracticeSubscription $r) => [
                    TextInput::make('amount')->label('Amount received (R)')->numeric()->minValue(0.01)->step(0.01)->required()
                        ->default($r->amount_cents > 0 ? Money::toDecimal((int) $r->amount_cents) : null),
                    Select::make('method')->options(['eft' => 'EFT', 'manual' => 'Other (manual)'])->default('eft')->required(),
                    Select::make('cycle')->label('Period paid')->options(PracticeSubscription::CYCLES)->default($r->billing_cycle)->required(),
                    TextInput::make('reference')->label('Bank reference')->maxLength(120),
                ])
                ->modalDescription('Advances the paid period by one cycle, issues a receipt and posts platform revenue to the ledger.')
                ->action(fn (PracticeSubscription $r, array $data) => $run(fn () => $svc()->markPaidManual($r, Money::cents((string) $data['amount']), $data['method'], $data['reference'] ?? null, $by(), $data['cycle']), 'Payment recorded')),

            Action::make('syncSeats')->label('Re-count seats')->icon('heroicon-o-user-group')->visible(fn (PracticeSubscription $r) => $can() && $r->plan?->isPerSeat())
                ->requiresConfirmation()
                ->action(fn (PracticeSubscription $r) => $run(fn () => $svc()->syncSeats($r, $by()), 'Seats updated')),

            Action::make('suspend')->label('Suspend')->icon('heroicon-o-no-symbol')->color('danger')->visible(fn (PracticeSubscription $r) => $can() && $r->status !== 'suspended')
                ->schema([TextInput::make('reason')->required()->maxLength(200)])
                ->action(fn (PracticeSubscription $r, array $data) => $run(fn () => $svc()->suspend($r, $data['reason'], $by()), 'Suspended')),

            Action::make('reactivate')->label('Reactivate')->icon('heroicon-o-play')->color('success')
                ->visible(fn (PracticeSubscription $r) => $can() && in_array($r->status, ['suspended', 'past_due', 'grace', 'cancelled'], true))
                ->requiresConfirmation()->modalDescription('Paid-up practices become active; otherwise they get a fresh grace period to pay.')
                ->action(fn (PracticeSubscription $r) => $run(fn () => $svc()->reactivate($r, $by()), 'Reactivated')),

            Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-circle')->color('gray')
                ->visible(fn (PracticeSubscription $r) => $can() && $r->status !== 'cancelled' && ! $r->cancel_at_period_end)
                ->schema([TextInput::make('reason')->required()->maxLength(200)->default('Cancelled by PromptAid')])
                ->modalDescription('Stops the PayFast card agreement. A paid-up practice keeps access until the end of its period.')
                ->action(fn (PracticeSubscription $r, array $data) => $run(fn () => $svc()->cancel($r, $by(), $data['reason'], true), 'Cancelled')),

            Action::make('simulateRenewal')->label('Simulate renewal')->icon('heroicon-o-beaker')->color('gray')
                ->visible(fn (PracticeSubscription $r) => $can() && PracticeBillingSettings::simulationAllowed() && $r->amount_cents > 0)
                ->requiresConfirmation()->modalDescription('TEST ONLY: runs a renewal payment through the same code path as a PayFast recurring ITN (no real money).')
                ->action(fn (PracticeSubscription $r) => $run(fn () => $svc()->simulateRenewal($r, $by()), 'Renewal simulated')),

            Action::make('evaluate')->label('Run billing check')->icon('heroicon-o-arrow-path')->color('gray')->visible($can)
                ->action(fn (PracticeSubscription $r) => $run(function () use ($r, $svc) {
                    $svc()->evaluate($r->fresh('plan'));
                    Gate::flush();
                }, 'Billing check done')),

            Action::make('note')->label('Add note')->icon('heroicon-o-pencil-square')->color('gray')->visible($can)
                ->schema([Textarea::make('note')->required()->maxLength(250)])
                ->action(fn (PracticeSubscription $r, array $data) => $run(fn () => $svc()->addNote($r, $data['note'], $by()), 'Note added')),
        ];
    }

    public static function getRelations(): array
    {
        return [InvoicesRelationManager::class, EventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPracticeSubscriptions::route('/'),
            'view' => ViewPracticeSubscription::route('/{record}'),
        ];
    }
}
