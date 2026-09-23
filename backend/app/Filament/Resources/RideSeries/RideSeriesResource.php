<?php

namespace App\Filament\Resources\RideSeries;

use App\Filament\Resources\RideSeries\Pages\EditRideSeries;
use App\Filament\Resources\RideSeries\Pages\ListRideSeries;
use App\Filament\Resources\RideSeries\Schemas\RideSeriesForm;
use App\Filament\Resources\RideSeries\Tables\RideSeriesTable;
use App\Models\RideSeries as RideSeriesModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RideSeriesResource extends Resource
{
    protected static ?string $model = RideSeriesModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    protected static ?string $navigationLabel = 'Recurring Series';

    public static function form(Schema $schema): Schema
    {
        return RideSeriesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RideSeriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRideSeries::route('/'),
            'edit' => EditRideSeries::route('/{record}/edit'),
        ];
    }
}
