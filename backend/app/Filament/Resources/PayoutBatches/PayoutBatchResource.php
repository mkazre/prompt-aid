<?php

namespace App\Filament\Resources\PayoutBatches;

use App\Filament\Resources\PayoutBatches\Pages\ListPayoutBatches;
use App\Filament\Resources\PayoutBatches\Pages\ViewPayoutBatch;
use App\Filament\Resources\PayoutBatches\RelationManagers\PayoutsRelationManager;
use App\Models\PayoutBatch;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Weekly provider payout batches: generate → approve → bank CSV → mark paid / failed. */
class PayoutBatchResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = true;

    protected static function permissionKey(): string
    {
        return 'payout-batches';
    }

    protected static ?string $model = PayoutBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Payout batches';

    protected static ?string $modelLabel = 'payout batch';

    protected static ?int $navigationSort = 4;

    public static function statusColor(?string $s): string
    {
        return match ($s) {
            'pending' => 'warning', 'approved' => 'info', 'completed', 'paid' => 'success', 'failed' => 'danger', default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('reference')->copyable(),
                TextEntry::make('status')->badge()->color(fn ($state) => static::statusColor($state))->formatStateUsing(fn ($state) => PayoutBatch::STATUSES[$state] ?? $state),
                TextEntry::make('total_amount')->label('Total')->money('ZAR'),
                TextEntry::make('payout_count')->label('Payouts'),
                TextEntry::make('period_start')->date('d M Y')->placeholder('start'),
                TextEntry::make('period_end')->label('Earnings up to')->date('d M Y'),
                TextEntry::make('approved_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('exported_at')->label('Bank file downloaded')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('meta.skipped')->label('Not included (and why)')->columnSpanFull()->listWithLineBreaks()->placeholder('—')->limitList(30)->expandableLimitedList(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold'),
                TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('period_end')->label('Earnings up to')->date('d M Y'),
                TextColumn::make('payout_count')->label('Payouts')->alignEnd(),
                TextColumn::make('total_amount')->label('Total')->money('ZAR')->alignEnd(),
                TextColumn::make('status')->badge()->color(fn ($state) => static::statusColor($state))->formatStateUsing(fn ($state) => PayoutBatch::STATUSES[$state] ?? $state),
                TextColumn::make('source')->badge()->color('gray'),
            ])
            ->filters([SelectFilter::make('status')->options(PayoutBatch::STATUSES)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [PayoutsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayoutBatches::route('/'),
            'view' => ViewPayoutBatch::route('/{record}'),
        ];
    }

    /** Edit-level permission for money-moving actions (approve, export, mark paid / failed). */
    public static function canOperate(): bool
    {
        return static::allowed('edit');
    }
}
