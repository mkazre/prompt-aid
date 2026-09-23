<?php

namespace App\Filament\Resources\MedicalSchemes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class MedicalSchemesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('code')->badge(),
                TextColumn::make('submission_mode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'electronic' => 'Electronic switch',
                        'portal' => 'Portal upload',
                        default => 'Manual PDF',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'electronic' => 'success',
                        'portal' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('memberships_count')->label('Members on platform')->counts('memberships'),
                ToggleColumn::make('active'),
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
