<?php

namespace App\Filament\Resources\Conditions;

use App\Filament\Resources\Conditions\Pages\CreateCondition;
use App\Filament\Resources\Conditions\Pages\EditCondition;
use App\Filament\Resources\Conditions\Pages\ListConditions;
use App\Filament\Resources\Conditions\Schemas\ConditionForm;
use App\Filament\Resources\Conditions\Tables\ConditionsTable;
use App\Models\Condition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * "Conditions & Symptoms" shown in search-as-you-type on the app and
 * website. Each maps to a doctor specialization so tapping a result lists
 * the right doctors. Super-admin only, same gating as EmergencyContactResource.
 */
class ConditionResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'conditions';
    }

    protected static ?string $model = Condition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Search: Conditions';

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
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.create');
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
        return ConditionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConditionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConditions::route('/'),
            'create' => CreateCondition::route('/create'),
            'edit' => EditCondition::route('/{record}/edit'),
        ];
    }
}
