<?php

namespace App\Filament\Resources\Appointments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('booking_ref')->searchable()->weight('bold'),
                TextColumn::make('patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('doctor.user.name')->label('Doctor')->searchable(),
                TextColumn::make('clinic.name')->searchable(),
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('start_time')->time()->sortable(),
                TextColumn::make('visit_type')->badge()->color('info'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed', 'confirmed' => 'success',
                        'pending', 'checked_in' => 'warning',
                        'cancelled', 'no_show' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('ride_requested')->boolean()->label('Shuttle'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked In',
                    'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No Show',
                ]),
                SelectFilter::make('visit_type')->options(['clinic' => 'At Clinic', 'telemed' => 'Telemedicine', 'home' => 'Home Visit']),
                SelectFilter::make('clinic_id')->relationship('clinic', 'name')->label('Clinic'),
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
