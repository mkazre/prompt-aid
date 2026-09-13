<?php

namespace App\Filament\Resources\Rides\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ride')
                    ->columns(2)
                    ->components([
                        Select::make('patient_profile_id')
                            ->relationship('patient', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                            ->searchable()->required(),
                        Select::make('driver_profile_id')
                            ->relationship('driver', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                            ->searchable(),
                        Select::make('appointment_id')->relationship('appointment', 'booking_ref')->searchable(),
                        Select::make('status')
                            ->options([
                                'requested' => 'Requested', 'accepted' => 'Accepted', 'driver_enroute' => 'Driver En Route',
                                'arrived' => 'Arrived', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                            ])
                            ->required()->default('requested'),
                        TextInput::make('pickup_address')->required()->columnSpanFull(),
                        TextInput::make('pickup_lat')->required()->numeric(),
                        TextInput::make('pickup_lng')->required()->numeric(),
                        TextInput::make('dropoff_address')->required()->columnSpanFull(),
                        TextInput::make('dropoff_lat')->required()->numeric(),
                        TextInput::make('dropoff_lng')->required()->numeric(),
                        TextInput::make('distance_km')->numeric()->suffix('km'),
                        TextInput::make('fare_estimate')->numeric()->prefix('R'),
                        TextInput::make('fare_final')->numeric()->prefix('R'),
                        Textarea::make('cancel_reason')->columnSpanFull(),
                    ]),
            ]);
    }
}
