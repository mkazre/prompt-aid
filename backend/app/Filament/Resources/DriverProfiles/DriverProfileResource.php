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

class DriverProfileResource extends Resource
{
    protected static ?string $model = DriverProfile::class;

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
