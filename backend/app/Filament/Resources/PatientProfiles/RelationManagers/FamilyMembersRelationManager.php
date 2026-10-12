<?php

namespace App\Filament\Resources\PatientProfiles\RelationManagers;

use App\Models\FamilyMember;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Dependants / family members the patient manages from their account. */
class FamilyMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'familyMembers';

    protected static ?string $title = 'Family members';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('relationship')->options(FamilyMember::RELATIONSHIPS)->required(),
            DatePicker::make('dob')->label('Date of birth')->maxDate(now()),
            Select::make('gender')->options(FamilyMember::GENDERS),
            TextInput::make('id_number')->label('ID number')->maxLength(40),
            TextInput::make('medical_aid_scheme')->label('Medical aid scheme')->maxLength(255),
            TextInput::make('medical_aid_number')->label('Medical aid number')->maxLength(60),
            Textarea::make('allergies')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('relationship')->formatStateUsing(fn ($state) => FamilyMember::RELATIONSHIPS[$state] ?? $state)->badge(),
                TextColumn::make('dob')->label('Born')->date('d M Y')->placeholder('—'),
                TextColumn::make('gender')->formatStateUsing(fn ($state) => FamilyMember::GENDERS[$state] ?? $state)->placeholder('—'),
                TextColumn::make('id_number')->label('ID')->placeholder('—')->toggleable(),
                TextColumn::make('medical_aid_scheme')->label('Medical aid')
                    ->formatStateUsing(fn ($state, FamilyMember $r) => trim($state.' '.($r->medical_aid_number ? '· '.$r->medical_aid_number : '')))
                    ->placeholder('—'),
                TextColumn::make('allergies')->limit(40)->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin())])
            ->recordActions([
                EditAction::make()->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin()),
                DeleteAction::make()->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin()),
            ]);
    }
}
