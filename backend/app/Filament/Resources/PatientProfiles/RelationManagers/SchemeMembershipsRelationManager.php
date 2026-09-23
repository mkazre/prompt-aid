<?php

namespace App\Filament\Resources\PatientProfiles\RelationManagers;

use App\Models\MedicalScheme;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SchemeMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'schemeMemberships';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('medical_scheme_id')
                ->label('Scheme')
                ->options(MedicalScheme::query()->where('active', true)->pluck('name', 'id'))
                ->required(),
            TextInput::make('member_number')->required(),
            TextInput::make('dependant_code')->nullable(),
            TextInput::make('main_member_name')->nullable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('member_number')
            ->columns([
                TextColumn::make('scheme.name'),
                TextColumn::make('member_number'),
                TextColumn::make('dependant_code')->placeholder('—'),
                TextColumn::make('verified_at')->dateTime()->placeholder('Not verified'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
