<?php

namespace App\Filament\Resources\Clinics\Schemas;

use App\Filament\Support\MediaLibraryPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClinicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Clinic details')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')->required()->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                        TextInput::make('slug')->required()->unique(ignoreRecord: true),
                        Select::make('clinic_admin_id')
                            ->relationship('admin', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Clinic Admin'),
                        Select::make('status')
                            ->options(['active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending'])
                            ->required()
                            ->default('pending'),
                        TextInput::make('phone')->tel(),
                        TextInput::make('email')->email(),
                        Textarea::make('description')->columnSpanFull(),
                    ]),
                Section::make('Location')
                    ->columns(2)
                    ->components([
                        TextInput::make('address')->required()->columnSpanFull(),
                        TextInput::make('city'),
                        TextInput::make('state'),
                        TextInput::make('country'),
                        TextInput::make('postal_code'),
                        TextInput::make('lat')->numeric(),
                        TextInput::make('lng')->numeric(),
                    ]),
                Section::make('Branding & specialties')
                    ->columns(2)
                    ->components([
                        FileUpload::make('logo')->image()->directory('clinics/logos'),
                        MediaLibraryPicker::for('logo'),
                        FileUpload::make('cover_image')->image()->directory('clinics/covers'),
                        MediaLibraryPicker::for('cover_image'),
                        TagsInput::make('specialties')->columnSpanFull(),
                    ]),
            ]);
    }
}
