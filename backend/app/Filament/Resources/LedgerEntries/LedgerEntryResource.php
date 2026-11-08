<?php

namespace App\Filament\Resources\LedgerEntries;

use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Models\CommissionRule;
use App\Models\LedgerEntry;
use App\Services\Finance\Accounts;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Ledger explorer (read-only; corrections are made with "Manual adjustment"). */
class LedgerEntryResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = true;

    protected static function permissionKey(): string
    {
        return 'ledger-entries';
    }

    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Ledger';

    protected static ?string $modelLabel = 'ledger entry';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('created_at')->dateTime('d M Y H:i'),
                TextEntry::make('account_type')->label('Account')->formatStateUsing(fn ($state, LedgerEntry $r) => $r->accountLabel().' ('.$state.')'),
                TextEntry::make('entry_type')->badge(),
                TextEntry::make('amount')->money('ZAR'),
                TextEntry::make('balance_after')->money('ZAR'),
                TextEntry::make('service_type')->placeholder('—'),
                TextEntry::make('source_type')->label('Source')->formatStateUsing(fn ($state, LedgerEntry $r) => $state.($r->source_id ? ' #'.$r->source_id : ''))->placeholder('—'),
                TextEntry::make('group_key')->label('Group')->placeholder('—')->copyable(),
                TextEntry::make('idempotency_key')->copyable(),
                TextEntry::make('creator.name')->label('Posted by')->placeholder('system'),
                TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                TextEntry::make('meta')->columnSpanFull()->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $state)->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('account_type')->label('Account')->formatStateUsing(fn ($state, LedgerEntry $r) => $r->accountLabel())->description(fn (LedgerEntry $r) => $r->account_type),
                TextColumn::make('entry_type')->label('Type')->badge()->color(fn ($state) => match ($state) {
                    'earning' => 'success', 'commission', 'fee' => 'info', 'refund_reversal' => 'danger', 'payout' => 'warning', default => 'gray',
                }),
                TextColumn::make('service_type')->label('Service')->placeholder('—')->toggleable(),
                TextColumn::make('amount')->money('ZAR')->sortable()->alignEnd(),
                TextColumn::make('balance_after')->label('Balance')->money('ZAR')->alignEnd()->toggleable(),
                TextColumn::make('description')->limit(60)->wrap()->searchable(),
                TextColumn::make('group_key')->label('Group')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('account_type')->label('Account type')->options(Accounts::TYPES),
                Filter::make('account')->schema([
                    Select::make('type')->label('Account type')->options(Accounts::TYPES)->live(),
                    Select::make('id')->label('Account')->searchable()
                        ->getSearchResultsUsing(fn (string $search, Get $get) => $get('type') ? Accounts::options((string) $get('type'), $search) : [])
                        ->options(fn (Get $get) => $get('type') && $get('type') !== 'platform' ? Accounts::options((string) $get('type')) : []),
                ])->query(fn (Builder $q, array $data) => $q->when($data['type'] ?? null, fn ($q, $t) => $q->where('account_type', $t))
                    ->when(($data['id'] ?? null) && ($data['type'] ?? null) !== 'platform', fn ($q) => $q->where('account_id', (int) $data['id']))),
                SelectFilter::make('entry_type')->label('Entry type')->options(LedgerEntry::ENTRY_TYPES),
                SelectFilter::make('service_type')->label('Service')->options(CommissionRule::SERVICES + ['subscription' => 'Subscriptions', 'other' => 'Other']),
                Filter::make('dates')->schema([
                    DatePicker::make('from'),
                    DatePicker::make('until'),
                ])->query(fn (Builder $q, array $data) => $q
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))),
                Filter::make('source')->schema([TextInput::make('group')->label('Group / source key (e.g. pi:12)')])
                    ->query(fn (Builder $q, array $data) => $q->when($data['group'] ?? null, fn ($q, $g) => $q->where('group_key', trim($g)))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerEntries::route('/'),
        ];
    }
}
