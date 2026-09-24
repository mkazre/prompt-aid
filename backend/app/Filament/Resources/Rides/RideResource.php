<?php

namespace App\Filament\Resources\Rides;

use App\Filament\Resources\Rides\Pages\CreateRide;
use App\Filament\Resources\Rides\Pages\EditRide;
use App\Filament\Resources\Rides\Pages\ListRides;
use App\Filament\Resources\Rides\Schemas\RideForm;
use App\Filament\Resources\Rides\Tables\RidesTable;
use App\Models\Ride;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RideResource extends Resource
{
    protected static ?string $model = Ride::class;

    // Ride dispatch is a logistics function, not a clinical one — clinic
    // staff/doctors have no legitimate need to see the ride marketplace.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;
    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    public static function form(Schema $schema): Schema
    {
        return RideForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RidesTable::configure($table);
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
            'index' => ListRides::route('/'),
            'create' => CreateRide::route('/create'),
            'edit' => EditRide::route('/{record}/edit'),
        ];
    }
}
