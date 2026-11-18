<?php

namespace App\Filament\Resources\PracticeSubscriptions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Timeline & audit';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i'),
                TextColumn::make('type')->badge(),
                TextColumn::make('message')->wrap(),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
            ]);
    }
}
