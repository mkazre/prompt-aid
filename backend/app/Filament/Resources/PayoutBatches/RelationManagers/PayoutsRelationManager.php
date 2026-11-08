<?php

namespace App\Filament\Resources\PayoutBatches\RelationManagers;

use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Models\Payout;
use App\Services\Finance\PayoutService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/** Payouts in a batch: mark paid (bank reference) or failed (amount returns to the provider's balance). */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payouts';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('reference')->weight('bold')->copyable(),
                TextColumn::make('account_type')->label('Provider')->formatStateUsing(fn ($state, Payout $r) => $r->accountLabel())->description(fn (Payout $r) => $r->account_type),
                TextColumn::make('payoutAccount.account_holder')->label('Beneficiary')->description(fn (Payout $r) => $r->payoutAccount ? $r->payoutAccount->bank_name.' · '.$r->payoutAccount->maskedNumber() : null),
                TextColumn::make('amount')->money('ZAR')->alignEnd(),
                TextColumn::make('status')->badge()->color(fn ($state) => PayoutBatchResource::statusColor($state)),
                TextColumn::make('bank_reference')->placeholder('—'),
                TextColumn::make('paid_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextColumn::make('failed_reason')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->recordActions([
                Action::make('paid')->label('Mark paid')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Payout $r) => $r->status === 'approved' && PayoutBatchResource::canOperate())
                    ->schema([TextInput::make('bank_reference')->label('Bank reference / trace number')->maxLength(100)])
                    ->action(function (Payout $r, array $data) {
                        $this->run(fn () => app(PayoutService::class)->markPaid($r, $data['bank_reference'] ?? null, Auth::user()), 'Marked paid');
                    }),
                Action::make('failed')->label('Mark failed')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Payout $r) => in_array($r->status, ['approved', 'pending', 'paid'], true) && PayoutBatchResource::canOperate())
                    ->schema([TextInput::make('reason')->required()->maxLength(200)->placeholder('e.g. account closed / invalid branch code')])
                    ->requiresConfirmation()
                    ->modalDescription('The amount is credited back to the provider\'s balance and paid in a later batch (after they fix their bank details).')
                    ->action(function (Payout $r, array $data) {
                        $this->run(fn () => app(PayoutService::class)->markFailed($r, (string) $data['reason'], Auth::user()), 'Marked failed — balance returned');
                    }),
            ]);
    }

    protected function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
