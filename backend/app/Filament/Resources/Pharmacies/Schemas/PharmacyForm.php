<?php

namespace App\Filament\Resources\Pharmacies\Schemas;

use App\Filament\Support\MediaLibraryPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PharmacyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->relationship('vendor', 'name')
                    ->required()
                    ->label('Vendor account'),
                TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),
                TextInput::make('slug')
                    ->required(),
                FileUpload::make('logo')->image()->directory('pharmacies/logos'),
                MediaLibraryPicker::for('logo'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('address'),
                TextInput::make('city'),
                TextInput::make('lat')
                    ->numeric(),
                TextInput::make('lng')
                    ->numeric(),
                TextInput::make('commission_rate')
                    ->required()
                    ->numeric()
                    ->default(10),
                TextInput::make('delivery_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('status')
                    ->options(['pending_approval' => 'Pending Approval', 'active' => 'Active', 'suspended' => 'Suspended'])
                    ->required()
                    ->default('pending_approval'),
            ]);
    }
}
