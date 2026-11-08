<?php

namespace App\Filament\Resources\PayoutAccounts;

use App\Filament\Resources\PayoutAccounts\Pages\ManagePayoutAccounts;
use App\Models\PayoutAccount;
use App\Services\Finance\Accounts;
use App\Services\Finance\PayoutAccounts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Provider bank details (submitted by providers; verified here before payouts). */
class PayoutAccountResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = false;

    protected static function permissionKey(): string
    {
        return 'payout-accounts';
    }

    protected static ?string $model = PayoutAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Bank details';

    protected static ?string $modelLabel = 'bank details';

    protected static ?string $pluralModelLabel = 'Bank details';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Select::make('account_type')->label('Provider type')->options(array_diff_key(Accounts::TYPES, ['platform' => 1]))->required()->live()
                    ->disabledOn('edit'),
                Select::make('account_id')->label('Provider')->required()->searchable()->disabledOn('edit')
                    ->getSearchResultsUsing(fn (string $search, Get $get) => $get('account_type') ? Accounts::options((string) $get('account_type'), $search) : [])
                    ->options(fn (Get $get) => $get('account_type') ? Accounts::options((string) $get('account_type')) : [])
                    ->getOptionLabelUsing(fn ($value, Get $get) => $value && $get('account_type') ? Accounts::label((string) $get('account_type'), (int) $value) : null),
                TextInput::make('account_holder')->required()->maxLength(150),
                Select::make('bank_name')->options(array_combine(PayoutAccounts::BANKS, PayoutAccounts::BANKS))->required()->searchable(),
                TextInput::make('branch_code')->required()->maxLength(12)->regex('/^\s*\d[\d\s]{4,9}\s*$/'),
                TextInput::make('account_number')->label('Account number')->maxLength(24)->regex('/^\s*\d[\d\s-]{5,19}\s*$/')
                    ->formatStateUsing(fn () => null)->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->placeholder(fn (?PayoutAccount $record) => $record ? $record->maskedNumber().' — leave empty to keep' : null),
                Select::make('bank_account_type')->options(PayoutAccount::BANK_ACCOUNT_TYPES)->required()->default('cheque'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('account_type')->label('Provider')->formatStateUsing(fn ($state, PayoutAccount $r) => $r->accountLabel())->description(fn (PayoutAccount $r) => $r->account_type),
                TextColumn::make('account_holder')->searchable(),
                TextColumn::make('bank_name'),
                TextColumn::make('branch_code'),
                TextColumn::make('account_last4')->label('Account')->formatStateUsing(fn ($state, PayoutAccount $r) => $r->maskedNumber()),
                TextColumn::make('bank_account_type')->label('Type')->formatStateUsing(fn ($state) => PayoutAccount::BANK_ACCOUNT_TYPES[$state] ?? $state),
                TextColumn::make('verified_at')->label('Verified')->dateTime('d M Y')->placeholder('Not verified')->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('account_type')->label('Provider type')->options(array_diff_key(Accounts::TYPES, ['platform' => 1])),
                TernaryFilter::make('verified_at')->label('Verified')->nullable(),
            ])
            ->recordActions([
                Action::make('verify')->label('Verify')->icon('heroicon-o-shield-check')->color('success')
                    ->visible(fn (PayoutAccount $r) => ! $r->isVerified() && static::allowed('edit'))
                    ->requiresConfirmation()
                    ->modalDescription('Confirm you checked these details (e.g. bank confirmation letter / account verification service) before payouts go to this account.')
                    ->action(function (PayoutAccount $r) {
                        app(PayoutAccounts::class)->verify($r, Auth::user());
                        Notification::make()->title('Bank details verified')->success()->send();
                    }),
                Action::make('unverify')->label('Revoke')->icon('heroicon-o-shield-exclamation')->color('gray')
                    ->visible(fn (PayoutAccount $r) => $r->isVerified() && static::allowed('edit'))
                    ->requiresConfirmation()
                    ->action(fn (PayoutAccount $r) => app(PayoutAccounts::class)->verify($r, Auth::user(), false)),
                EditAction::make()->visible(fn () => static::allowed('edit'))
                    ->after(fn (PayoutAccount $record) => $record->wasChanged(['account_holder', 'bank_name', 'branch_code', 'account_number', 'bank_account_type'])
                        ? $record->forceFill(['verified_at' => null, 'verified_by' => null])->save() : null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePayoutAccounts::route('/'),
        ];
    }
}
