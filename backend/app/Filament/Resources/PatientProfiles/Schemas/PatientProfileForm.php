<?php

namespace App\Filament\Resources\PatientProfiles\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PatientProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                DatePicker::make('dob'),
                TextInput::make('gender'),
                TextInput::make('blood_group'),
                Textarea::make('address')
                    ->columnSpanFull(),
                TextInput::make('lat')
                    ->numeric(),
                TextInput::make('lng')
                    ->numeric(),
                TextInput::make('emergency_contact_name'),
                TextInput::make('emergency_contact_phone')
                    ->tel(),
                Textarea::make('allergies')
                    ->columnSpanFull(),
                Textarea::make('chronic_conditions')
                    ->columnSpanFull(),
            ]);
    }
}
