<?php

namespace App\Filament\Resources\TriageSubmissions;

use App\Filament\Resources\TriageSubmissions\Pages\ListTriageSubmissions;
use App\Filament\Resources\TriageSubmissions\Pages\ViewTriageSubmission;
use App\Filament\Resources\TriageSubmissions\Schemas\TriageSubmissionInfolist;
use App\Filament\Resources\TriageSubmissions\Tables\TriageSubmissionsTable;
use App\Models\TriageSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Read-only monitor over every pre-triage result the public tool has
 * produced (see TriageSubmissionController — the tool itself never
 * dispatches anything, it only ever tells the patient to call 10177/112;
 * this is purely so staff can see who assessed themselves as urgent).
 */
class TriageSubmissionResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'triage-submissions';
    }

    protected static ?string $model = TriageSubmission::class;

    // System-wide safety monitor, not tied to any one clinic — super_admin only.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?string $navigationLabel = 'Triage Monitor';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin() && (bool) auth()->user()?->hasPermission(static::permissionKey().'.view');
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

    public static function infolist(Schema $schema): Schema
    {
        return TriageSubmissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TriageSubmissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTriageSubmissions::route('/'),
            'view' => ViewTriageSubmission::route('/{record}'),
        ];
    }
}
