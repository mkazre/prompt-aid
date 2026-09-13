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

class RideRateCardResource extends Resource
{
    protected static ?string $model = RideRateCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;
    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
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
