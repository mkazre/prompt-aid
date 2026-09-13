<?php

namespace App\Filament\Resources\DriverProfiles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DriverProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->searchable()->weight('bold'),
                TextColumn::make('vehicle_make')->searchable()->formatStateUsing(fn ($record) => trim("{$record->vehicle_make} {$record->vehicle_model}")),
                TextColumn::make('vehicle_plate_no')->searchable()->label('Plate'),
                TextColumn::make('availability')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'available' => 'success',
                        'busy' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('rating_avg')->label('Rating')->icon('heroicon-s-star')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending_approval' => 'warning',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('availability')->options(['available' => 'Available', 'busy' => 'Busy', 'offline' => 'Offline']),
                SelectFilter::make('status')->options(['active' => 'Active', 'pending_approval' => 'Pending Approval', 'suspended' => 'Suspended']),
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
