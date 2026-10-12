<?php

namespace App\Filament\Resources\PatientProfiles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PatientProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->searchable()->weight('bold'),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('user.phone')->label('Phone')->searchable(),
                TextColumn::make('dob')->date()->sortable(),
                TextColumn::make('gender')->badge(),
                TextColumn::make('blood_group')->badge()->color('danger'),
                TextColumn::make('emergency_contact_name')->label('Emergency Contact')->searchable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Streamed CSV download (no queue): current filters/search/sort, or the ticked rows.
            ->headerActions([
                \App\Support\CsvExport::headerAction('patient-profiles'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \App\Support\CsvExport::bulkAction('patient-profiles'),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
