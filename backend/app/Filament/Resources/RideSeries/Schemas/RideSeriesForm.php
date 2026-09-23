<?php

namespace App\Filament\Resources\RideSeries\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RideSeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vehicle_type')
                    ->options(['sedan' => 'Sedan', 'suv' => 'SUV', 'van' => 'Van', 'wheelchair_accessible' => 'Wheelchair Accessible', 'stretcher' => 'Stretcher'])
                    ->required(),
                TextInput::make('pattern.time')->label('Pickup time')->placeholder('09:00')->required(),
                TextInput::make('pattern.until')->label('Repeats until')->placeholder('YYYY-MM-DD')->required(),
                TextInput::make('pickup.address')->label('Pickup address')->required(),
                TextInput::make('dropoff.address')->label('Drop-off address')->required(),
                Toggle::make('active')->default(true)->required(),
            ]);
    }
}
