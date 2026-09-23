<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Filament\Resources\Pages\Pages\Build;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->weight('bold'),
                TextColumn::make('slug')->searchable()->color('gray'),
                TextColumn::make('kind')->badge()->color('info'),
                TextColumn::make('entity_type')->badge()->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'warning'),
                IconColumn::make('is_home')->boolean()->label('Home'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
                SelectFilter::make('kind')->options(['page' => 'Page', 'archive' => 'Archive', 'single' => 'Single']),
            ])
            ->recordActions([
                Action::make('build')
                    ->label('Edit Blocks')
                    ->icon('heroicon-o-squares-2x2')
                    ->url(fn ($record) => Build::getUrl(['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
