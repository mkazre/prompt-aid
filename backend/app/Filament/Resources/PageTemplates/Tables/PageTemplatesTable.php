<?php

namespace App\Filament\Resources\PageTemplates\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PageTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('entity_type')->badge(),
                TextColumn::make('page.title')->label('Built from'),
                TextColumn::make('priority')->sortable(),
                IconColumn::make('is_default')->boolean(),
            ])
            ->filters([
                SelectFilter::make('entity_type')->options([
                    'doctor' => 'Doctor', 'clinic' => 'Clinic', 'pharmacy' => 'Pharmacy',
                    'product' => 'Product', 'service' => 'Service', 'third_party' => 'Third-party partner',
                ]),
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
