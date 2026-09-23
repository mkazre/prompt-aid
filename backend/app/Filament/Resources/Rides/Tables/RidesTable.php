<?php

namespace App\Filament\Resources\Rides\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RidesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('ride_ref')->searchable()->weight('bold'),
                TextColumn::make('patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('driver.user.name')->label('Driver')->searchable()->placeholder('Unassigned'),
                TextColumn::make('pickup_address')->limit(30),
                TextColumn::make('dropoff_address')->limit(30),
                TextColumn::make('distance_km')->numeric()->suffix(' km')->sortable(),
                TextColumn::make('fare_final')->money('ZAR')->sortable()->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'requested', 'accepted', 'driver_enroute', 'arrived', 'in_progress' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requested_at')->dateTime()->sortable(),
                TextColumn::make('scheduled_for')->dateTime()->sortable()->placeholder('—')->toggleable(),
                TextColumn::make('is_return')->badge()->label('Type')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Return leg' : 'Outbound')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'requested' => 'Requested', 'accepted' => 'Accepted', 'driver_enroute' => 'Driver En Route',
                    'arrived' => 'Arrived', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
