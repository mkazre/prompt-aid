<?php

namespace App\Filament\Resources\DriverProfiles;

use App\Filament\Resources\DriverProfiles\Pages\CreateDriverProfile;
use App\Filament\Resources\DriverProfiles\Pages\EditDriverProfile;
use App\Filament\Resources\DriverProfiles\Pages\ListDriverProfiles;
use App\Filament\Resources\DriverProfiles\Schemas\DriverProfileForm;
use App\Filament\Resources\DriverProfiles\Tables\DriverProfilesTable;
use App\Models\DriverProfile;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DriverProfileResource extends Resource
{
    protected static ?string $model = DriverProfile::class;

    // Ride dispatch is a logistics function, not a clinical one.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;
    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    public static function form(Schema $schema): Schema
    {
        return DriverProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DriverProfilesTable::configure($table);
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
            'index' => ListDriverProfiles::route('/'),
            'create' => CreateDriverProfile::route('/create'),
            'edit' => EditDriverProfile::route('/{record}/edit'),
        ];
    }
}
