<?php

namespace App\Filament\Resources\AppWidgets\Tables;

use App\Support\Widgets\WidgetCatalog;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AppWidgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('title')->searchable()->weight('bold')->description(fn ($record) => $record->key),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => WidgetCatalog::ROLES[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('screen')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => WidgetCatalog::SCREENS[$state] ?? $state)
                    ->sortable(),
                ToggleColumn::make('is_enabled')->label('Enabled'),
                TextColumn::make('updated_at')->since()->label('Updated')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->options(WidgetCatalog::ROLES),
                SelectFilter::make('screen')->options(WidgetCatalog::SCREENS),
                TernaryFilter::make('is_enabled')->label('Enabled'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
