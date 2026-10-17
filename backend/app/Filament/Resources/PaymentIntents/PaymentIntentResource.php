<?php

namespace App\Filament\Resources\PaymentIntents;

use App\Filament\Resources\PaymentIntents\Pages\ListPaymentIntents;
use App\Filament\Resources\PaymentIntents\Pages\ViewPaymentIntent;
use App\Models\PaymentIntent;
use App\Services\Payments\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
use UnitEnum;

/** Online payment attempts (read-only) with refund / mark-refunded / re-check actions. */
class PaymentIntentResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = true;

    protected static function permissionKey(): string
    {
        return 'payment-intents';
    }

    protected static ?string $model = PaymentIntent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Online payments';

    protected static ?string $modelLabel = 'online payment';

    protected static ?int $navigationSort = 20;

    public static function statusColor(?string $s): string
    {
        return match ($s) {
            'paid' => 'success', 'failed' => 'danger', 'refunded' => 'gray', 'cancelled' => 'gray', 'processing' => 'warning', default => 'info',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('status')->badge()->color(fn ($state) => static::statusColor($state)),
                    TextEntry::make('amount')->money('ZAR'),
                    TextEntry::make('gateway')->badge(),
                    TextEntry::make('channel'),
                    TextEntry::make('gateway_ref')->label('Gateway reference')->placeholder('—')->copyable(),
                    TextEntry::make('user.name')->label('Customer')->placeholder('—'),
                    TextEntry::make('payable_type')->label('For')->formatStateUsing(fn ($state, PaymentIntent $r) => ($r->payableAlias() ?? class_basename((string) $state)).' #'.$r->payable_id),
                    TextEntry::make('description')->placeholder('—'),
                    TextEntry::make('refunded_amount')->money('ZAR'),
                    TextEntry::make('paid_at')->dateTime('d M Y H:i')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime('d M Y H:i'),
                    TextEntry::make('failure_reason')->placeholder('—')->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->copyable()->weight('bold'),
                TextColumn::make('user.name')->label('Customer')->searchable()->placeholder('—'),
                TextColumn::make('payable_type')->label('For')->formatStateUsing(fn ($state, PaymentIntent $r) => ($r->payableAlias() ?? '?').' #'.$r->payable_id),
                TextColumn::make('amount')->money('ZAR')->sortable(),
                TextColumn::make('gateway')->badge(),
                TextColumn::make('status')->badge()->color(fn ($state) => static::statusColor($state)),
                TextColumn::make('gateway_ref')->label('Gateway ref')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PaymentIntent::STATUSES),
                SelectFilter::make('gateway')->options(['payfast' => 'PayFast', 'paystack' => 'Paystack', 'ozow' => 'Ozow', 'mock' => 'Test (mock)']),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('recheck')->label('Re-check')->icon('heroicon-o-arrow-path')
                    ->visible(fn (PaymentIntent $r) => in_array($r->status, ['pending', 'processing'], true) && $r->gateway !== 'mock')
                    ->action(function (PaymentIntent $r) {
                        $r = app(PaymentService::class)->refresh($r, 0);
                        Notification::make()->title('Status: '.$r->status)->send();
                    }),
                Action::make('refund')->label('Refund')->icon('heroicon-o-receipt-refund')->color('danger')
                    ->visible(fn (PaymentIntent $r) => $r->status === 'paid' && static::allowed('edit'))
                    ->schema([
                        TextInput::make('amount')->label('Amount (R)')->numeric()->required()->minValue(0.01)
                            ->default(fn (PaymentIntent $r) => round($r->amount - $r->refunded_amount, 2)),
                        TextInput::make('reason')->maxLength(100)->default('Refund'),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (PaymentIntent $r, array $data) => static::notify(app(PaymentService::class)->refund($r, (float) $data['amount'], (string) $data['reason']))),
                Action::make('markRefunded')->label('Mark refunded')->icon('heroicon-o-check')->color('gray')
                    ->visible(fn (PaymentIntent $r) => $r->status === 'paid' && static::allowed('edit'))
                    ->schema([
                        TextInput::make('amount')->label('Amount refunded outside PromptAid (R)')->numeric()->required()->minValue(0.01)
                            ->default(fn (PaymentIntent $r) => round($r->amount - $r->refunded_amount, 2)),
                        TextInput::make('reason')->maxLength(100)->default('Refunded in gateway dashboard'),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (PaymentIntent $r, array $data) => static::notify(app(PaymentService::class)->refund($r, (float) $data['amount'], (string) $data['reason'], manual: true))),
            ]);
    }

    protected static function notify(array $result): void
    {
        Notification::make()->title($result['message'])->{$result['success'] ? 'success' : 'danger'}()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentIntents::route('/'),
            'view' => ViewPaymentIntent::route('/{record}'),
        ];
    }
}
