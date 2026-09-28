<?php

namespace App\Filament\Resources\ProviderApplications;

use App\Filament\Resources\ProviderApplications\Pages\EditProviderApplication;
use App\Filament\Resources\ProviderApplications\Pages\ListProviderApplications;
use App\Filament\Resources\ProviderApplications\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ProviderApplications\Schemas\ProviderApplicationForm;
use App\Filament\Resources\ProviderApplications\Tables\ProviderApplicationsTable;
use App\Models\ProviderApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The provider-side lead pipeline for the public "For Providers" form:
 * document upload + this admin approval step before a Clinic/DoctorProfile/
 * Pharmacy record goes live. Super-admin only, same reasoning as
 * EmergencyContactResource — approving a provider application creates a
 * real live record (and, for doctor/pharmacy, a User login), so getting
 * this wrong has real consequences.
 */
class ProviderApplicationResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'provider-applications';
    }

    protected static ?string $model = ProviderApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Provider Applications';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.edit');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.delete');
    }

    public static function form(Schema $schema): Schema
    {
        return ProviderApplicationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProviderApplicationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviderApplications::route('/'),
            'edit' => EditProviderApplication::route('/{record}/edit'),
        ];
    }
}
