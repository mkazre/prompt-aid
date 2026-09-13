<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_no')
                    ->required(),
                Select::make('patient_profile_id')
                    ->relationship('patient', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                    ->searchable()
                    ->required(),
                Select::make('pharmacy_id')
                    ->relationship('pharmacy', 'name')
                    ->required(),
                Select::make('prescription_upload_id')
                    ->relationship('prescriptionUpload', 'id'),
                TextInput::make('subtotal')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('delivery_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('commission_amount')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('delivery_address'),
                TextInput::make('delivery_lat')
                    ->numeric(),
                TextInput::make('delivery_lng')
                    ->numeric(),
                Select::make('status')
                    ->options([
                        'pending_payment' => 'Pending Payment', 'awaiting_prescription_review' => 'Awaiting Prescription Review',
                        'confirmed' => 'Confirmed', 'preparing' => 'Preparing', 'out_for_delivery' => 'Out for Delivery',
                        'delivered' => 'Delivered', 'cancelled' => 'Cancelled',
                    ])
                    ->required()
                    ->default('pending_payment'),
                Select::make('payment_status')
                    ->options(['unpaid' => 'Unpaid', 'paid' => 'Paid', 'refunded' => 'Refunded'])
                    ->required()
                    ->default('unpaid'),
            ]);
    }
}
