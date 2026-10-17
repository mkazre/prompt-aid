<?php

namespace App\Filament\Resources\DoctorProfiles\Schemas;

use Filament\Forms\Components\Repeater;
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
                Toggle::make('offers_video')
                    ->label('Offers virtual / video consults')
                    ->helperText('Shows this doctor under "Virtual consult" on the website and app.')
                    ->default(true),
                Toggle::make('is_accepting_appointments')
                    ->label('Accepting new appointments')
                    ->helperText('Turn this off to hide the "book now" option from patients while you are away, fully booked, etc. Your profile stays visible either way.')
                    ->default(true),
                Select::make('availability')
                    ->label('Live status (shown to patients)')
                    ->helperText('Green: can see a patient within 30 min (receives "see a GP now" requests). Orange: available today at the next slot in the queue. Red: not available today — patients can book a future day.')
                    ->options([
                        'available' => 'Green — available in 30 min',
                        'busy' => 'Orange — available today (next slot)',
                        'offline' => 'Red — not available today',
                    ])
                    ->required()
                    ->default('offline'),
                // Weekly hours = bookable slots (same data the app's Settings → Sessions edits).
                Repeater::make('availabilities')
                    ->label('Weekly hours (booking slots)')
                    ->relationship()
                    ->schema([
                        Select::make('weekday')->options(['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '0' => 'Sunday'])->required(),
                        Select::make('clinic_id')->label('Clinic')->relationship('clinic', 'name')->searchable()->preload()->required(),
                        TextInput::make('start_time')->label('From (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/')->required(),
                        TextInput::make('end_time')->label('To (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/')->required(),
                        TextInput::make('slot_minutes')->label('Slot (minutes)')->numeric()->minValue(5)->maxValue(240)->default(30)->required(),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }
}
