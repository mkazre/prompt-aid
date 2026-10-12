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
                TextColumn::make('expires_at')->label('Expires')->badge()
                    ->formatStateUsing(fn ($state, $record) => \App\Services\Commerce\DocumentExpiryService::label($record->expires_at))
                    ->color(fn ($record) => \App\Services\Commerce\DocumentExpiryService::badgeColor($record->expires_at))
                    ->placeholder('No expiry')
                    ->sortable(),
                TextColumn::make('created_at')->dateTime()->label('Uploaded'),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => Storage::disk('public')->url($record->path))
                    ->openUrlInNewTab(),
                Action::make('setExpiry')
                    ->label('Set expiry')
                    ->icon('heroicon-o-calendar')
                    ->color('gray')
                    ->fillForm(fn ($record) => ['expires_at' => $record->expires_at?->toDateString()])
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('expires_at')->label('Expiry date')
                            ->helperText('Reminders go out 30, 7 and 0 days before. Leave empty if the document does not expire.'),
                    ])
                    ->action(fn ($record, array $data) => $record->update(['expires_at' => $data['expires_at'] ?: null])),
            ]);
    }
}
