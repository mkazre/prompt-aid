<?php

namespace App\Filament\Resources\NotificationLog\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationLogTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('notifiable.name')->label('Sent to')->searchable(),
                TextColumn::make('data.title')->label('Title')->limit(50),
                TextColumn::make('data.body')->label('Body')->limit(60)->placeholder('—'),
                IconColumn::make('read_at')->label('Read')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('read_at')
                    ->label('Status')
                    ->options(['unread' => 'Unread', 'read' => 'Read'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'unread' => $query->whereNull('read_at'),
                        'read' => $query->whereNotNull('read_at'),
                        default => $query,
                    }),
            ]);
    }
}
