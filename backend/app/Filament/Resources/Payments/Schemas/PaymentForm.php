<?php

namespace App\Filament\Resources\Payments\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('invoice_id')
                    ->relationship('invoice', 'id')
                    ->required(),
                Select::make('method')
                    ->options(['cash' => 'Cash', 'card' => 'Card', 'mobile_money' => 'Mobile Money', 'insurance' => 'Insurance'])
                    ->required()
                    ->default('cash'),
                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->prefix('R'),
                Select::make('status')
                    ->options(['pending' => 'Pending', 'success' => 'Success', 'failed' => 'Failed', 'refunded' => 'Refunded'])
                    ->required()
                    ->default('pending'),
                TextInput::make('gateway'),
                TextInput::make('gateway_ref'),
            ]);
    }
}
