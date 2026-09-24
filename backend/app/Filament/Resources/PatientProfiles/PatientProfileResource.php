<?php

namespace App\Filament\Resources\PatientProfiles;

use App\Filament\Resources\PatientProfiles\Pages\CreatePatientProfile;
use App\Filament\Resources\PatientProfiles\Pages\EditPatientProfile;
use App\Filament\Resources\PatientProfiles\Pages\ListPatientProfiles;
use App\Filament\Resources\PatientProfiles\RelationManagers\SchemeMembershipsRelationManager;
use App\Filament\Resources\PatientProfiles\Schemas\PatientProfileForm;
use App\Filament\Resources\PatientProfiles\Tables\PatientProfilesTable;
use App\Models\PatientProfile;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PatientProfileResource extends Resource
{
    use \App\Filament\Concerns\ScopesToClinicOrDoctor;

    protected static ?string $model = PatientProfile::class;

    // Patients aren't tied to one clinic directly — scope to patients who
    // have at least one appointment at this clinic_admin's clinic(s) / with
    // this doctor.
    protected static function clinicScope(): string
    {
        return 'appointments.clinic_id';
    }

    protected static function doctorScope(): string
    {
        return 'appointments.doctor_profile_id';
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    public static function form(Schema $schema): Schema
    {
        return PatientProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PatientProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SchemeMembershipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatientProfiles::route('/'),
            'create' => CreatePatientProfile::route('/create'),
            'edit' => EditPatientProfile::route('/{record}/edit'),
        ];
    }
}
