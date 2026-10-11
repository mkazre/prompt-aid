<?php

namespace App\Filament\Resources\AppWidgets;

use App\Filament\Resources\AppWidgets\Pages\CreateAppWidget;
use App\Filament\Resources\AppWidgets\Pages\EditAppWidget;
use App\Filament\Resources\AppWidgets\Pages\ListAppWidgets;
use App\Filament\Resources\AppWidgets\Schemas\AppWidgetForm;
use App\Filament\Resources\AppWidgets\Tables\AppWidgetsTable;
use App\Models\AppWidget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The config-driven widgets on every role's app home (and the patient
 * find-care / shop / record screens, doctor calendar, patient web
 * dashboard). Admins toggle, reorder, retitle and tune each widget's
 * settings here; the data shown is always computed live per user by
 * App\Support\Widgets\WidgetDataService. Super-admin only — same idiom as
 * EmergencyContactResource.
 */
class AppWidgetResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'app-widgets';
    }

    protected static ?string $model = AppWidget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'App Widgets';

    protected static ?int $navigationSort = 5;

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
        return AppWidgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppWidgetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppWidgets::route('/'),
            'create' => CreateAppWidget::route('/create'),
            'edit' => EditAppWidget::route('/{record}/edit'),
        ];
    }
}
