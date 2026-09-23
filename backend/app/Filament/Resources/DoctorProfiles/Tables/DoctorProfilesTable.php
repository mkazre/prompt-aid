<?php

namespace App\Filament\Resources\DoctorProfiles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DoctorProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->searchable()->weight('bold'),
                TextColumn::make('specialization')->searchable()->badge()->color('info'),
                TextColumn::make('experience_years')->numeric()->sortable()->suffix(' yrs'),
                TextColumn::make('consultation_fee')->money('ZAR')->sortable(),
                TextColumn::make('rating_avg')->label('Rating')->icon('heroicon-s-star')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending_approval' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_accepting_appointments')
                    ->label('Accepting')
                    ->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive', 'pending_approval' => 'Pending Approval']),
                TernaryFilter::make('is_accepting_appointments')->label('Accepting appointments'),
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
