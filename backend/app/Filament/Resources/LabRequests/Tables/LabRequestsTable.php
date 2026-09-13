<?php

namespace App\Filament\Resources\LabRequests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LabRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('request_ref')->searchable()->weight('bold'),
                TextColumn::make('doctor.user.name')->label('Doctor')->searchable(),
                TextColumn::make('patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('thirdParty.company_name')->label('Lab Partner')->placeholder('Unassigned'),
                TextColumn::make('priority')->badge()->color(fn (string $state) => $state === 'urgent' ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'requested', 'accepted', 'sample_collected', 'processing' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requested_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'requested' => 'Requested', 'accepted' => 'Accepted', 'sample_collected' => 'Sample Collected',
                    'processing' => 'Processing', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                ]),
                SelectFilter::make('priority')->options(['routine' => 'Routine', 'urgent' => 'Urgent']),
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
