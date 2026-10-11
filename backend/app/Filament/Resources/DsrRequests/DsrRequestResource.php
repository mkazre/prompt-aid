<?php

namespace App\Filament\Resources\DsrRequests;

use App\Filament\Resources\DsrRequests\Pages\CreateDsrRequest;
use App\Filament\Resources\DsrRequests\Pages\EditDsrRequest;
use App\Filament\Resources\DsrRequests\Pages\ListDsrRequests;
use App\Models\DsrRequest;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * POPIA data subject request queue (access / correction / deletion /
 * objection) with a 30-day SLA badge. Deletion is admin-reviewed: the
 * "Approve & anonymise" action on the edit page runs AccountAnonymiser.
 */
class DsrRequestResource extends Resource
{
    use \App\Filament\Concerns\GatesSuperAdminPermissions;

    protected static function permissionKey(): string
    {
        return 'dsr-requests';
    }

    protected static ?string $model = DsrRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Privacy';

    protected static ?string $navigationLabel = 'Data subject requests';

    protected static ?string $modelLabel = 'data subject request';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        try {
            if (! auth()->user()?->isSuperAdmin()) {
                return null;
            }
            $open = DsrRequest::query()->open()->count();

            return $open > 0 ? (string) $open : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        try {
            return DsrRequest::query()->open()->where('due_at', '<', now())->exists() ? 'danger' : 'warning';
        } catch (\Throwable) {
            return 'warning';
        }
    }

    public static function canDelete($record): bool
    {
        return false; // The request record is itself compliance evidence.
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')->columns(2)->schema([
                Select::make('user_id')
                    ->label('Data subject (account)')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->required()
                    ->disabledOn('edit'),
                Select::make('type')->options(DsrRequest::TYPES)->required()->disabledOn('edit'),
                Textarea::make('details')->rows(3)->columnSpanFull()->disabledOn('edit'),
                Placeholder::make('sla')
                    ->label('Due')
                    ->content(fn (?DsrRequest $record) => $record?->due_at
                        ? $record->due_at->toDayDateTimeString().' ('.$record->due_at->diffForHumans().')'
                        : 'Set automatically: received + '.\App\Support\PrivacySettings::int('privacy_dsr_sla_days').' days')
                    ->hiddenOn('create'),
                Placeholder::make('received')
                    ->label('Received')
                    ->content(fn (?DsrRequest $record) => $record?->created_at?->toDayDateTimeString().' via '.$record?->channel)
                    ->hiddenOn('create'),
            ]),
            Section::make('Handling')->columns(2)->schema([
                Select::make('status')->options(DsrRequest::STATUSES)->required()->default(DsrRequest::STATUS_RECEIVED),
                Placeholder::make('handler')
                    ->label('Handled by')
                    ->content(fn (?DsrRequest $record) => $record?->handler?->name ?? '—')
                    ->hiddenOn('create'),
                Textarea::make('notes')
                    ->label('Internal notes / response to the data subject')
                    ->helperText('For correction/objection requests, record what was changed or why the objection was or wasn\'t upheld. Shown to the user once the request is closed.')
                    ->rows(4)->columnSpanFull(),
            ]),
            Section::make('Outcome')->columns(2)->hiddenOn('create')->schema([
                Placeholder::make('export')
                    ->label('Data export')
                    ->content(fn (?DsrRequest $record) => $record?->export_path
                        ? 'Generated, '.number_format(($record->export_size ?? 0) / 1024, 1).' KB — available to the user until '.$record->export_expires_at?->toDayDateTimeString()
                        : 'None'),
                Placeholder::make('anonymised')
                    ->label('Anonymisation')
                    ->content(fn (?DsrRequest $record) => $record?->anonymised_at
                        ? 'Done '.$record->anonymised_at->toDayDateTimeString()
                            .($record->retain_clinical_until ? '; clinical records retained until '.$record->retain_clinical_until->toDateString() : '')
                            .($record->fully_anonymised_at ? '; fully anonymised '.$record->fully_anonymised_at->toDateString() : '')
                        : '—'),
                Textarea::make('retention_summary')
                    ->label('What was removed / kept and why')
                    ->rows(8)->columnSpanFull()->disabled()->dehydrated(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_at', 'asc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.name')->label('Data subject')->searchable()->description(fn (DsrRequest $r) => $r->user?->email),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => DsrRequest::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'deletion' ? 'danger' : 'gray'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => DsrRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success', 'rejected' => 'gray', 'in_progress' => 'info', default => 'warning',
                    }),
                TextColumn::make('due_at')->label('SLA')->sortable()
                    ->badge()
                    ->formatStateUsing(fn ($state, DsrRequest $r) => match ($r->slaState()) {
                        'overdue' => 'Overdue '.$r->due_at?->diffForHumans(null, true),
                        'due_soon' => 'Due in '.$r->due_at?->diffForHumans(null, true),
                        'closed' => 'Closed',
                        default => 'Due '.$r->due_at?->format('d M'),
                    })
                    ->color(fn ($state, DsrRequest $r) => match ($r->slaState()) {
                        'overdue' => 'danger', 'due_soon' => 'warning', 'closed' => 'gray', default => 'success',
                    }),
                TextColumn::make('created_at')->label('Received')->dateTime('d M Y')->sortable(),
                TextColumn::make('handler.name')->label('Handler')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->options(DsrRequest::TYPES),
                SelectFilter::make('status')->options(DsrRequest::STATUSES),
                Filter::make('open')->label('Open only')->default()
                    ->query(fn (Builder $q) => $q->whereIn('status', [DsrRequest::STATUS_RECEIVED, DsrRequest::STATUS_IN_PROGRESS])),
                Filter::make('overdue')->label('Overdue')
                    ->query(fn (Builder $q) => $q->whereIn('status', [DsrRequest::STATUS_RECEIVED, DsrRequest::STATUS_IN_PROGRESS])->where('due_at', '<', now())),
            ])
            ->recordActions([EditAction::make()->label('Handle')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDsrRequests::route('/'),
            'create' => CreateDsrRequest::route('/create'),
            'edit' => EditDsrRequest::route('/{record}/edit'),
        ];
    }
}
