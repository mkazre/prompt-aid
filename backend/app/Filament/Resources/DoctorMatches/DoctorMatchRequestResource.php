<?php

namespace App\Filament\Resources\DoctorMatches;

use App\Filament\Resources\DoctorMatches\Pages\ListDoctorMatchRequests;
use App\Filament\Resources\DoctorMatches\Pages\ViewDoctorMatchRequest;
use App\Models\DoctorMatchRequest;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Read-only log of "See a doctor now" requests with the offer timeline
 * (who was offered, accepted/declined/expired, when). Super-admin only —
 * doctors work their own offers from the Live consults page.
 */
class DoctorMatchRequestResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'doctor-matches';
    }

    protected static ?string $model = DoctorMatchRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    protected static ?string $navigationLabel = 'On-demand requests';

    protected static ?string $modelLabel = 'on-demand request';

    protected static ?string $slug = 'on-demand-requests';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['patient.user', 'doctor.user', 'appointment']);

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'matched', 'accepted' => 'success',
            'searching', 'offered' => 'warning',
            'expired', 'declined' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('appointment.booking_ref')->label('Ref')->searchable()->weight('bold'),
                TextColumn::make('created_at')->label('Requested')->dateTime('d M H:i')->sortable(),
                TextColumn::make('patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('specialty')->searchable(),
                TextColumn::make('mode')->badge()->formatStateUsing(fn (string $state) => $state === 'video' ? 'Video' : 'In person'),
                TextColumn::make('status')->badge()->color(fn (?string $state) => static::statusColor($state)),
                TextColumn::make('attempts')->label('Offers'),
                TextColumn::make('doctor.user.name')->label('Doctor')->placeholder('—'),
                TextColumn::make('price')->money('ZAR'),
                TextColumn::make('time_to_accept')->label('Time to accept')
                    ->state(fn (DoctorMatchRequest $r) => $r->matched_at ? max(0, $r->matched_at->getTimestamp() - $r->created_at->getTimestamp()) : null)
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : gmdate(((int) $state) >= 3600 ? 'H:i:s' : 'i:s', (int) $state))
                    ->placeholder('—'),
                TextColumn::make('outcome_reason')->label('Outcome')->formatStateUsing(fn (?string $state) => $state ? (string) str($state)->headline() : '—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'searching' => 'Searching', 'matched' => 'Matched', 'expired' => 'Expired', 'cancelled' => 'Cancelled',
                ]),
                SelectFilter::make('mode')->options(['video' => 'Video', 'in_person' => 'In person']),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('appointment.booking_ref')->label('Ref'),
                            TextEntry::make('status')->badge()->color(fn (?string $state) => static::statusColor($state)),
                            TextEntry::make('outcome_reason')->label('Outcome')->formatStateUsing(fn (?string $state) => $state ? (string) str($state)->headline() : '—')->placeholder('—'),
                            TextEntry::make('appointment.status')->label('Appointment')->badge(),
                            TextEntry::make('patient.user.name')->label('Patient'),
                            TextEntry::make('specialty'),
                            TextEntry::make('mode')->formatStateUsing(fn (string $state) => $state === 'video' ? 'Video' : 'In person'),
                            TextEntry::make('doctor.user.name')->label('Doctor')->placeholder('—'),
                            TextEntry::make('created_at')->label('Requested')->dateTime(),
                            TextEntry::make('expires_at')->label('Search deadline')->dateTime(),
                            TextEntry::make('matched_at')->label('Matched')->dateTime()->placeholder('—'),
                            TextEntry::make('cancelled_at')->label('Cancelled')->dateTime()->placeholder('—'),
                        ]),
                    ]),
                Section::make('Price (locked at request)')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('base_fee')->label('Base fee')->money('ZAR'),
                            TextEntry::make('multiplier')->label('Multiplier')->formatStateUsing(fn ($state) => '×'.$state),
                            TextEntry::make('price')->money('ZAR'),
                            TextEntry::make('cancel_fee')->label('Cancellation fee')->money('ZAR'),
                            TextEntry::make('price_breakdown.base_source')->label('Base source')->formatStateUsing(fn (?string $state) => $state ? (string) str($state)->headline() : '—')->placeholder('—'),
                            TextEntry::make('price_breakdown.time_label')->label('Time band')->placeholder('—'),
                            TextEntry::make('price_breakdown.surge_multiplier')->label('Surge')->formatStateUsing(fn ($state) => '×'.$state)->placeholder('—'),
                            TextEntry::make('invoice.invoice_no')->label('Invoice')->placeholder('—'),
                        ]),
                    ]),
                Section::make('Offer timeline')
                    ->schema([
                        RepeatableEntry::make('offers')
                            ->label('')
                            ->schema([
                                TextEntry::make('doctor.user.name')->label('Doctor'),
                                TextEntry::make('status')->badge()->color(fn (?string $state) => static::statusColor($state)),
                                TextEntry::make('distance_km')->label('Distance')->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 1).' km')->placeholder('—'),
                                TextEntry::make('offered_at')->label('Offered')->dateTime('H:i:s'),
                                TextEntry::make('expires_at')->label('Expires')->dateTime('H:i:s'),
                                TextEntry::make('responded_at')->label('Responded')->dateTime('H:i:s')->placeholder('—'),
                            ])
                            ->columns(6)
                            ->placeholder('No doctor has been offered this request yet.'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDoctorMatchRequests::route('/'),
            'view' => ViewDoctorMatchRequest::route('/{record}'),
        ];
    }
}
