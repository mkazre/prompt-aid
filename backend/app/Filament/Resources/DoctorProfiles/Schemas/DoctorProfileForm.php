<?php

namespace App\Filament\Resources\DoctorProfiles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DoctorProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('specialization')
                    ->required(),
                TextInput::make('qualification'),
                TextInput::make('experience_years')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('bio')
                    ->columnSpanFull(),
                TextInput::make('consultation_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('registration_no'),
                Select::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive', 'pending_approval' => 'Pending Approval'])
                    ->required()
                    ->default('pending_approval'),
                Toggle::make('is_accepting_appointments')
                    ->label('Accepting new appointments')
                    ->helperText('Turn this off to hide the "book now" option from patients while you are away, fully booked, etc. Your profile stays visible either way.')
                    ->default(true),
            ]);
    }
}
