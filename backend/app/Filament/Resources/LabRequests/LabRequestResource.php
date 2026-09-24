<?php

namespace App\Filament\Resources\LabRequests;

use App\Filament\Resources\LabRequests\Pages\CreateLabRequest;
use App\Filament\Resources\LabRequests\Pages\EditLabRequest;
use App\Filament\Resources\LabRequests\Pages\ListLabRequests;
use App\Filament\Resources\LabRequests\Schemas\LabRequestForm;
use App\Filament\Resources\LabRequests\Tables\LabRequestsTable;
use App\Models\LabRequest;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LabRequestResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'lab-requests';
    }

    protected static ?string $model = LabRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isDoctor() && $user->doctorProfile) {
            return $query->where('doctor_profile_id', $user->doctorProfile->id);
        }

        if ($user->isThirdParty() && $user->thirdPartyProfile) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('third_party_profile_id', $user->thirdPartyProfile->id)
                    ->orWhereNull('third_party_profile_id'); // can also see open/unassigned requests
            });
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return LabRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LabRequestsTable::configure($table);
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
            'index' => ListLabRequests::route('/'),
            'create' => CreateLabRequest::route('/create'),
            'edit' => EditLabRequest::route('/{record}/edit'),
        ];
    }
}
