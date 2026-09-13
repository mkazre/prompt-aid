<?php

namespace App\Filament\Resources\ThirdPartyProfiles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ThirdPartyProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('company_name')
                    ->required(),
                Select::make('service_type')
                    ->options(['lab' => 'Lab / Diagnostics', 'imaging' => 'Imaging', 'pharmacy_delivery' => 'Pharmacy Delivery', 'home_nursing' => 'Home Nursing'])
                    ->required()
                    ->default('lab'),
                TextInput::make('license_no'),
                Textarea::make('description')
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(['pending_approval' => 'Pending Approval', 'active' => 'Active', 'suspended' => 'Suspended'])
                    ->required()
                    ->default('pending_approval'),
            ]);
    }
}
