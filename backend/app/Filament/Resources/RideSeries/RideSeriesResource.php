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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class RideSeriesResource extends Resource
{
    protected static ?string $model = RideSeriesModel::class;

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
