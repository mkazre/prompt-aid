<?php

namespace App\Filament\Resources\Encounters\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class EncounterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('appointment_id')
                    ->relationship('appointment', 'id')
                    ->required(),
                Textarea::make('vitals')
                    ->columnSpanFull(),
                Textarea::make('chief_complaint')
                    ->columnSpanFull(),
                Textarea::make('diagnosis')
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->columnSpanFull(),
                DatePicker::make('follow_up_date'),
            ]);
    }
}
