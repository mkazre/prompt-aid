<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    /**
     * "Send test alert": in-app + push (InAppNotifier) and the panel bell
     * with sound (StaffNotifier) to this user — to check sounds on their
     * phone/browser. Also used on the Edit user page header.
     */
    public static function testAlertAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('sendTestAlert')
            ->label('Send test alert')
            ->icon('heroicon-o-bell-alert')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (\App\Models\User $record) => 'Send a test alert to '.$record->name.'?')
            ->modalDescription('They get an in-app notification with a phone push (sound), and staff also get the panel bell with sound. Use this to check alerts work on their phone and browser.')
            ->modalSubmitActionLabel('Send test alert')
            ->action(function (\App\Models\User $record) {
                $result = \App\Support\TestAlert::send($record, auth()->user());
                \Filament\Notifications\Notification::make()
                    ->title('Test alert sent')
                    ->body(\App\Support\TestAlert::summary($record, $result))
                    ->success()
                    ->send();
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('avatar')->circular()->defaultImageUrl(asset('images/logo.png')),
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('email')->label('Email address')->searchable(),
                TextColumn::make('phone')->searchable()->toggleable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'clinic_admin' => 'warning',
                        'doctor' => 'info',
                        'driver' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str($state)->headline()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->options([
                    'super_admin' => 'Super Admin', 'clinic_admin' => 'Clinic Admin',
                    'doctor' => 'Doctor', 'driver' => 'Driver', 'patient' => 'Patient',
                ]),
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended']),
            ])
            ->recordActions([
                self::testAlertAction(),
                EditAction::make(),
            ])
            // Streamed CSV download (no queue): current filters/search/sort, or the ticked rows.
            ->headerActions([
                \App\Support\CsvExport::headerAction('users'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \App\Support\CsvExport::bulkAction('users'),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
