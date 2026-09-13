<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('email')->label('Email address')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('phone')->tel(),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->revealable()
                    ->helperText('Leave blank to keep the current password when editing.'),
                Select::make('role')
                    ->options([
                        'super_admin' => 'Super Admin',
                        'clinic_admin' => 'Clinic Admin',
                        'doctor' => 'Doctor',
                        'driver' => 'Driver',
                        'patient' => 'Patient',
                    ])
                    ->required()
                    ->default('patient'),
                Select::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'])
                    ->required()
                    ->default('active'),
                FileUpload::make('avatar')->image()->directory('avatars')->circleCropper(),
            ]);
    }
}
