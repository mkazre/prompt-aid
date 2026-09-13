<?php

namespace App\Filament\Resources\RideRateCards\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RideRateCardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vehicle_type')
                    ->options(['sedan' => 'Sedan', 'suv' => 'SUV', 'van' => 'Van', 'wheelchair_accessible' => 'Wheelchair Accessible'])
                    ->required(),
                TextInput::make('base_fare')->label('Base fare (R)')
                    ->required()
                    ->numeric()
                    ->default(30),
                TextInput::make('per_km_rate')->label('Rate per km (R)')
                    ->required()
                    ->numeric()
                    ->default(12),
                TextInput::make('per_minute_rate')->label('Rate per minute (R)')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('minimum_fare')->label('Minimum fare (R)')
                    ->required()
                    ->numeric()
                    ->default(30),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
