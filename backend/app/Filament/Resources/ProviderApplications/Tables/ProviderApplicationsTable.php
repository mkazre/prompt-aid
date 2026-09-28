<?php

namespace App\Filament\Resources\ProviderApplications\Tables;

use App\Models\ProviderApplication;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProviderApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('business_name')->searchable()->weight('bold'),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                TextColumn::make('contact_name')->label('Contact'),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('phone'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => match ($state) {
                        ProviderApplication::STATUS_APPROVED => 'success',
                        ProviderApplication::STATUS_SUBMITTED, ProviderApplication::STATUS_UNDER_REVIEW => 'warning',
                        ProviderApplication::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ProviderApplication::STATUS_SUBMITTED => 'Submitted',
                    ProviderApplication::STATUS_UNDER_REVIEW => 'Under review',
                    ProviderApplication::STATUS_APPROVED => 'Approved',
                    ProviderApplication::STATUS_REJECTED => 'Rejected',
                ]),
                SelectFilter::make('type')->options([
                    ProviderApplication::TYPE_CLINIC => 'Clinic',
                    ProviderApplication::TYPE_DOCTOR => 'Doctor',
                    ProviderApplication::TYPE_PHARMACY => 'Pharmacy',
                    ProviderApplication::TYPE_THIRD_PARTY => 'Lab / specialist / other',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
