<?php

namespace App\Filament\Resources\ProviderApplications\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * View/download only — documents arrive via the public "For Providers"
 * upload, so there's no admin create form here (mirrors the read side of
 * Claims\RelationManagers\DocumentsRelationManager).
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Submitted documents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->columns([
                TextColumn::make('label'),
                TextColumn::make('original_name')->label('File'),
                TextColumn::make('created_at')->dateTime()->label('Uploaded'),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => Storage::disk('public')->url($record->path))
                    ->openUrlInNewTab(),
            ]);
    }
}
