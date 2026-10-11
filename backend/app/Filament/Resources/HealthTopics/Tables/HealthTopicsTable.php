<?php

namespace App\Filament\Resources\HealthTopics\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class HealthTopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('month', 'desc')
            ->columns([
                TextColumn::make('month')->date('F Y')->sortable(),
                TextColumn::make('title')->searchable()->weight('bold')->wrap(),
                TextColumn::make('testimonials_count')->counts('testimonials')->label('Testimonials'),
                ToggleColumn::make('is_published')->label('Published'),
            ])
            ->recordActions([
                Action::make('view')->label('View on site')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => route('topic.view', $record))->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
