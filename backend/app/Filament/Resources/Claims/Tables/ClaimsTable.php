<?php

namespace App\Filament\Resources\Claims\Tables;

use App\Models\Claim;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('claim_ref')->label('Claim')->weight('bold'),
                TextColumn::make('membership.patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('membership.scheme.name')->label('Scheme'),
                TextColumn::make('membership.member_number')->label('Member #'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'part_paid' => 'Part paid',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'submitted' => 'warning',
                        'part_paid' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('amount_claimed')->money('ZAR')->sortable(),
                TextColumn::make('amount_paid')->money('ZAR')->sortable(),
                TextColumn::make('submitted_at')->dateTime()->sortable()->placeholder('Not submitted'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Claim::STATUS_DRAFT => 'Draft', Claim::STATUS_SUBMITTED => 'Submitted',
                    Claim::STATUS_ACCEPTED => 'Accepted', Claim::STATUS_PART_PAID => 'Part paid',
                    Claim::STATUS_REJECTED => 'Rejected',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
