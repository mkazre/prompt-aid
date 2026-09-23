<?php

namespace App\Filament\Resources\TriageSubmissions\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TriageSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('reference')->weight('bold'),
                TextColumn::make('level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'red' => 'danger', 'orange' => 'warning', 'yellow' => 'warning', default => 'success',
                    }),
                TextColumn::make('user.name')->label('Patient')->placeholder('Anonymous'),
                TextColumn::make('age_band'),
                IconColumn::make('staff_notified')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('level')->options([
                    'red' => 'Red', 'orange' => 'Orange', 'yellow' => 'Yellow', 'green' => 'Green',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
