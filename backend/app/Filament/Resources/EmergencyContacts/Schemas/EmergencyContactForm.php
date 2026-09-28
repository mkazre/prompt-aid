<?php

namespace App\Filament\Resources\EmergencyContacts\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmergencyContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('label')->required()->maxLength(255),
                        TextInput::make('phone')
                            ->label('Phone (display text)')
                            ->helperText('What patients see, e.g. "0800 567 567".')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('tel_url')
                            ->label('tel: URL')
                            ->helperText('The FULL href including the scheme, e.g. "tel:0800567567" — this is used exactly as typed, so leaving off "tel:" will silently break the call button.')
                            ->required()
                            ->maxLength(255)
                            ->rule('regex:/^tel:/')
                            ->validationMessages(['regex' => 'Must start with "tel:" — e.g. tel:0800567567.']),
                        TextInput::make('sort_order')->numeric()->default(0)->required(),
                        Toggle::make('is_primary')
                            ->label('Primary')
                            ->helperText('The one big "Call ambulance now" CTA. Only one contact should be primary.')
                            ->default(false),
                        Toggle::make('is_active')->default(true)->required(),
                    ])->columns(2),
            ]);
    }
}
