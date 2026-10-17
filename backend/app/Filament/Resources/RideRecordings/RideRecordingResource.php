<?php

namespace App\Filament\Resources\RideRecordings;

use App\Filament\Resources\RideRecordings\Pages\ListRideRecordings;
use App\Filament\Resources\RideRecordings\Pages\ViewRideRecording;
use App\Filament\Resources\RideRecordings\Tables\RideRecordingsTable;
use App\Models\RideRecording;
use App\Services\Safety\RideRecordingService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Trip audio recordings (safety evidence). Super admins always; other staff
 * need `ride-recordings.view` (list/play/download) and
 * `ride-recordings.manage` (legal hold, delete). Every play/download from
 * here goes through a signed URL issued to the staff member and is logged.
 * Recordings are never created or edited here — only by the ride's patient
 * or driver.
 */
class RideRecordingResource extends Resource
{
    protected static ?string $model = RideRecording::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-microphone';

    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    protected static ?string $navigationLabel = 'Trip recordings';

    protected static ?string $modelLabel = 'trip recording';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['ride', 'user']);

        return static::canViewAny() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return RideRecordingService::isStaffViewer(Auth::user());
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
        return RideRecordingService::isStaffManager(Auth::user()) && ! $record->legal_hold;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return RideRecordingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRideRecordings::route('/'),
            'view' => ViewRideRecording::route('/{record}'),
        ];
    }
}
