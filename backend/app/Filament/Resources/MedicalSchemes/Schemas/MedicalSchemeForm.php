<?php

namespace App\Filament\Resources\MedicalSchemes\Schemas;

use App\Models\MedicalScheme;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MedicalSchemeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(10),
                Select::make('submission_mode')
                    ->options([
                        MedicalScheme::SUBMISSION_ELECTRONIC => 'Electronic switch',
                        MedicalScheme::SUBMISSION_PORTAL => 'Portal upload',
                        MedicalScheme::SUBMISSION_MANUAL => 'Manual PDF',
                    ])
                    ->default(MedicalScheme::SUBMISSION_MANUAL)
                    ->required(),
                TextInput::make('claims_endpoint')
                    ->label('Claims endpoint (once a switching partner is signed)')
                    ->url()
                    ->nullable(),
                Toggle::make('active')->default(true)->required(),
            ]);
    }
}
