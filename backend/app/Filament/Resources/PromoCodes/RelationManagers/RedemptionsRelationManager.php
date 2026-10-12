<?php

namespace App\Filament\Resources\PromoCodes\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only usage history of a promo code. */
class RedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptions';

    protected static ?string $title = 'Usage';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Used')->dateTime('d M Y H:i'),
                TextColumn::make('user.name')->label('Patient')->placeholder('—'),
                TextColumn::make('order.order_no')->label('Order')->placeholder('—'),
                TextColumn::make('context')->badge(),
                TextColumn::make('subtotal')->label('Basket')->money('ZAR'),
                TextColumn::make('discount_amount')->label('Discount')->money('ZAR'),
            ]);
    }
}
