<?php

namespace App\Filament\Resources\DriverProfiles\RelationManagers;

use App\Models\DriverDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Compliance documents uploaded by the driver from the mobile app.
 * Admins download, approve or reject (with a note the driver sees).
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Compliance documents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->columns([
                TextColumn::make('type')->formatStateUsing(fn ($state) => DriverDocument::TYPES[$state] ?? $state),
                TextColumn::make('original_name')->label('File')->limit(40),
                TextColumn::make('expires_at')->label('Expires')->badge()
                    ->formatStateUsing(fn ($state, $record) => \App\Services\Commerce\DocumentExpiryService::label($record->expires_at))
                    ->color(fn ($record) => \App\Services\Commerce\DocumentExpiryService::badgeColor($record->expires_at))
                    ->placeholder('No expiry')
                    ->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('review_note')->limit(40)->toggleable(),
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
                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status !== 'approved')
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['status' => 'approved', 'review_note' => null])),
                Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status !== 'rejected')
                    ->schema([
                        TextInput::make('review_note')->label('Reason shown to the driver')->required()->maxLength(255),
                    ])
                    ->action(fn ($record, array $data) => $record->update(['status' => 'rejected', 'review_note' => $data['review_note']])),
            ]);
    }
}
