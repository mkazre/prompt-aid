<?php

namespace App\Filament\Resources\RideSeries\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RideSeriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('vehicle_type')->badge(),
                TextColumn::make('pattern.time')->label('Time'),
                TextColumn::make('pattern.until')->label('Until'),
                TextColumn::make('pickup.address')->label('Pickup')->limit(30),
                TextColumn::make('dropoff.address')->label('Drop-off')->limit(30),
                ToggleColumn::make('active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
