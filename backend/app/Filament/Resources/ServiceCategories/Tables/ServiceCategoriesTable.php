<?php

namespace App\Filament\Resources\ServiceCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('icon')->label(''),
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('slug'),
                TextColumn::make('services_count')->label('Services')->counts('services'),
                TextColumn::make('products_count')->label('Products')->counts('products'),
                TextColumn::make('sort')->sortable(),
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
