<?php

namespace App\Filament\Resources\DoctorProfiles;

use App\Filament\Resources\DoctorProfiles\Pages\CreateDoctorProfile;
use App\Filament\Resources\DoctorProfiles\Pages\EditDoctorProfile;
use App\Filament\Resources\DoctorProfiles\Pages\ListDoctorProfiles;
use App\Filament\Resources\DoctorProfiles\Schemas\DoctorProfileForm;
use App\Filament\Resources\DoctorProfiles\Tables\DoctorProfilesTable;
use App\Models\DoctorProfile;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DoctorProfileResource extends Resource
{
    protected static ?string $model = DoctorProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;
    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    public static function form(Schema $schema): Schema
    {
        return DoctorProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DoctorProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDoctorProfiles::route('/'),
            'create' => CreateDoctorProfile::route('/create'),
            'edit' => EditDoctorProfile::route('/{record}/edit'),
        ];
    }
}
