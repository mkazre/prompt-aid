<?php

namespace App\Filament\Resources\PromoCodes\Tables;

use App\Models\PromoCode;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PromoCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->searchable()->weight('bold')->copyable(),
                TextColumn::make('description')->limit(40)->toggleable(),
                TextColumn::make('value')->label('Discount')
                    ->formatStateUsing(fn (PromoCode $record) => $record->label()),
                TextColumn::make('applies_to')->label('Applies to')->badge()
                    ->formatStateUsing(fn ($state) => PromoCode::APPLIES_TO[$state] ?? $state),
                TextColumn::make('redemptions_count')->label('Used')->sortable()
                    ->formatStateUsing(fn ($state, PromoCode $record) => $record->max_uses ? "{$state} / {$record->max_uses}" : (string) $state),
                TextColumn::make('redemptions_sum_discount_amount')->label('Discount given')->money('ZAR')->toggleable(),
                TextColumn::make('min_spend')->label('Min spend')->money('ZAR')->placeholder('—')->toggleable(),
                TextColumn::make('starts_at')->label('Starts')->dateTime('d M Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('ends_at')->label('Ends')->dateTime('d M Y H:i')->placeholder('—')->sortable()
                    ->color(fn (PromoCode $record) => $record->ends_at?->isPast() ? 'danger' : null),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('applies_to')->options(PromoCode::APPLIES_TO),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
