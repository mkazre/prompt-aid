<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('clinic_id')
                    ->relationship('clinic', 'name')
                    ->required(),
                Select::make('doctor_profile_id')
                    ->relationship('doctor', 'specialization')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                    ->searchable()
                    ->label('Doctor (optional)'),
                TextInput::make('name')
                    ->required(),
                CheckboxList::make('serviceCategories')
                    ->relationship('serviceCategories', 'name')
                    ->columns(2),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('R'),
                TextInput::make('duration_minutes')
                    ->required()
                    ->numeric()
                    ->default(30),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
