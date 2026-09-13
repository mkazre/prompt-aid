<?php

namespace App\Filament\Resources\ThirdPartyProfiles;

use App\Filament\Resources\ThirdPartyProfiles\Pages\CreateThirdPartyProfile;
use App\Filament\Resources\ThirdPartyProfiles\Pages\EditThirdPartyProfile;
use App\Filament\Resources\ThirdPartyProfiles\Pages\ListThirdPartyProfiles;
use App\Filament\Resources\ThirdPartyProfiles\Schemas\ThirdPartyProfileForm;
use App\Filament\Resources\ThirdPartyProfiles\Tables\ThirdPartyProfilesTable;
use App\Models\ThirdPartyProfile;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ThirdPartyProfileResource extends Resource
{
    protected static ?string $model = ThirdPartyProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;
protected static string|UnitEnum|null $navigationGroup = 'Users & Access';

    public static function form(Schema $schema): Schema
    {
        return ThirdPartyProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ThirdPartyProfilesTable::configure($table);
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
            'index' => ListThirdPartyProfiles::route('/'),
            'create' => CreateThirdPartyProfile::route('/create'),
            'edit' => EditThirdPartyProfile::route('/{record}/edit'),
        ];
    }
}
