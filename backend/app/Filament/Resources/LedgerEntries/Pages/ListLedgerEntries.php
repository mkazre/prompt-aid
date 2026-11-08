<?php

namespace App\Filament\Resources\LedgerEntries\Pages;

use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Models\CommissionRule;
use App\Services\Finance\Accounts;
use App\Services\Finance\LedgerService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')->label('Manual adjustment')->icon('heroicon-o-pencil-square')->color('warning')
                ->visible(fn () => (bool) Auth::user()?->isSuperAdmin() && Auth::user()->hasPermission('ledger-entries.edit'))
                ->modalDescription('Credit (+) or debit (−) a provider or the platform. A reason is required; the line and an audit-log row record who made it.')
                ->schema([
                    Select::make('type')->label('Account type')->options(Accounts::TYPES)->required()->live(),
                    Select::make('id')->label('Account')->searchable()
                        ->required(fn (Get $get) => $get('type') !== 'platform')
                        ->visible(fn (Get $get) => $get('type') && $get('type') !== 'platform')
                        ->getSearchResultsUsing(fn (string $search, Get $get) => Accounts::options((string) $get('type'), $search))
                        ->options(fn (Get $get) => $get('type') ? Accounts::options((string) $get('type')) : []),
                    TextInput::make('amount')->label('Amount (R) — negative to debit')->numeric()->required()->step(0.01),
                    Select::make('service_type')->label('Service (optional)')->options(CommissionRule::SERVICES),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(500),
                ])
                ->requiresConfirmation()
                ->action(function (array $data) {
                    try {
                        $e = app(LedgerService::class)->adjust((string) $data['type'], (int) ($data['id'] ?? 0), Money::cents((string) $data['amount']),
                            (string) $data['reason'], Auth::user(), $data['service_type'] ?? null);
                        Notification::make()->title('Adjustment posted: '.Money::format(Money::cents((string) $e->amount)).' — new balance '.Money::format(Money::cents((string) $e->balance_after)))->success()->send();
                    } catch (\RuntimeException $ex) {
                        Notification::make()->title($ex->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
