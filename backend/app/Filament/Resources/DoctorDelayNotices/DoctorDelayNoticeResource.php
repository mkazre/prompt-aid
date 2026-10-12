<?php

namespace App\Filament\Resources\DoctorDelayNotices;

use App\Filament\Resources\DoctorDelayNotices\Pages\ListDoctorDelayNotices;
use App\Models\DoctorDelayNotice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Read-only log of "Running late" alerts doctors sent to their patients. */
class DoctorDelayNoticeResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'doctor-delay-notices';
    }

    protected static ?string $model = DoctorDelayNotice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    protected static ?string $navigationLabel = 'Running-late alerts';

    protected static ?string $modelLabel = 'running-late alert';

    protected static ?int $navigationSort = 40;

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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Sent')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('doctor.user.name')->label('Doctor')->searchable(),
                TextColumn::make('minutes')->label('Delay')->suffix(' min')->badge()->color(fn ($state) => $state >= 45 ? 'danger' : 'warning'),
                TextColumn::make('patients_notified')->label('Patients told'),
                TextColumn::make('message')->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('sentBy.name')->label('Sent by')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('doctor_profile_id')->label('Doctor')
                    ->relationship('doctor.user', 'name')->searchable()->preload(),
                Filter::make('today')->label('Today only')
                    ->query(fn (Builder $q) => $q->whereDate('for_date', now(\App\Support\CommerceSettings::timezone())->toDateString())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDoctorDelayNotices::route('/'),
        ];
    }
}
