<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('invoice_no')
                    ->required(),
                Select::make('appointment_id')
                    ->relationship('appointment', 'booking_ref'),
                Select::make('patient_profile_id')
                    ->relationship('patient', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                    ->searchable()
                    ->required(),
                Select::make('clinic_id')
                    ->relationship('clinic', 'name')
                    ->required(),
                TextInput::make('subtotal')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('tax')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('discount')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('status')
                    ->options(['unpaid' => 'Unpaid', 'paid' => 'Paid', 'partially_paid' => 'Partially Paid', 'refunded' => 'Refunded'])
                    ->required()
                    ->default('unpaid'),
                DatePicker::make('due_date'),
            ]);
    }
}
