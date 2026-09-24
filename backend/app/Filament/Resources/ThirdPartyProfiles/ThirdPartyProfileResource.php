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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ThirdPartyProfileResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'third-party-profiles';
    }

    protected static ?string $model = ThirdPartyProfile::class;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user && $user->isThirdParty()) {
            return $query->where('user_id', $user->id);
        }

        return $query;
    }

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
