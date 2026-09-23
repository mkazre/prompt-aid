<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success', 'deleted' => 'danger', default => 'warning',
                    }),
                TextColumn::make('auditable_type')->label('Model')->formatStateUsing(fn (string $state) => class_basename($state)),
                TextColumn::make('auditable_id')->label('ID'),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
                TextColumn::make('ip'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
