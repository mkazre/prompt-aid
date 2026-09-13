<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('avatar')->circular()->defaultImageUrl(asset('images/logo.png')),
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('email')->label('Email address')->searchable(),
                TextColumn::make('phone')->searchable()->toggleable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'clinic_admin' => 'warning',
                        'doctor' => 'info',
                        'driver' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str($state)->headline()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->options([
                    'super_admin' => 'Super Admin', 'clinic_admin' => 'Clinic Admin',
                    'doctor' => 'Doctor', 'driver' => 'Driver', 'patient' => 'Patient',
                ]),
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended']),
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
