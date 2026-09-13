<?php

namespace App\Filament\Resources\DriverProfiles\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DriverProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('license_no')
                    ->required(),
                DatePicker::make('license_expiry'),
                TextInput::make('vehicle_make'),
                TextInput::make('vehicle_model'),
                TextInput::make('vehicle_color'),
                TextInput::make('vehicle_plate_no'),
                Select::make('vehicle_type')
                    ->options(['sedan' => 'Sedan', 'suv' => 'SUV', 'van' => 'Van', 'wheelchair_accessible' => 'Wheelchair Accessible'])
                    ->required()
                    ->default('sedan'),
                Select::make('availability')
                    ->options(['offline' => 'Offline', 'available' => 'Available', 'busy' => 'Busy'])
                    ->required()
                    ->default('offline'),
                Select::make('status')
                    ->options(['pending_approval' => 'Pending Approval', 'active' => 'Active', 'suspended' => 'Suspended'])
                    ->required()
                    ->default('pending_approval'),
            ]);
    }
}
