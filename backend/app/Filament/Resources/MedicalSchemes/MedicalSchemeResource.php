<?php

namespace App\Filament\Resources\MedicalSchemes;

use App\Filament\Resources\MedicalSchemes\Pages\CreateMedicalScheme;
use App\Filament\Resources\MedicalSchemes\Pages\EditMedicalScheme;
use App\Filament\Resources\MedicalSchemes\Pages\ListMedicalSchemes;
use App\Filament\Resources\MedicalSchemes\Schemas\MedicalSchemeForm;
use App\Filament\Resources\MedicalSchemes\Tables\MedicalSchemesTable;
use App\Models\MedicalScheme;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MedicalSchemeResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'medical-schemes';
    }

    protected static ?string $model = MedicalScheme::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Medical Schemes';

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->isSuperAdmin() || auth()->user()?->isClinicAdmin())
            && (bool) auth()->user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function form(Schema $schema): Schema
    {
        return MedicalSchemeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MedicalSchemesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedicalSchemes::route('/'),
            'create' => CreateMedicalScheme::route('/create'),
            'edit' => EditMedicalScheme::route('/{record}/edit'),
        ];
    }
}
