<?php

namespace App\Filament\Resources\RideRateCards;

use App\Filament\Resources\RideRateCards\Pages\CreateRideRateCard;
use App\Filament\Resources\RideRateCards\Pages\EditRideRateCard;
use App\Filament\Resources\RideRateCards\Pages\ListRideRateCards;
use App\Filament\Resources\RideRateCards\Schemas\RideRateCardForm;
use App\Filament\Resources\RideRateCards\Tables\RideRateCardsTable;
use App\Models\RideRateCard;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RideRateCardResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'ride-rate-cards';
    }

    protected static ?string $model = RideRateCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;
    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    // Pricing config is a super_admin power regardless of what a custom
    // Role might later be checked to grant.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.create');
    }

    public static function canEdit($record): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.edit');
    }

    public static function canDelete($record): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.delete');
    }

    public static function form(Schema $schema): Schema
    {
        return RideRateCardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RideRateCardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRideRateCards::route('/'),
            'create' => CreateRideRateCard::route('/create'),
            'edit' => EditRideRateCard::route('/{record}/edit'),
        ];
    }
}
