<?php

namespace App\Filament\Resources\Claims;

use App\Filament\Resources\Claims\Pages\EditClaim;
use App\Filament\Resources\Claims\Pages\ListClaims;
use App\Filament\Resources\Claims\Schemas\ClaimForm;
use App\Filament\Resources\Claims\Tables\ClaimsTable;
use App\Models\Claim;
use App\Models\Invoice;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    /**
     * Claims are only ever raised against Invoices (see ClaimService), so
     * scope via the morphed claimable's clinic/doctor rather than a direct
     * column — the shared ScopesToClinicOrDoctor trait can't express a
     * whereHasMorph, so this is a bespoke override.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isClinicAdmin()) {
            $clinicIds = $user->clinicsAdministered()->pluck('id');

            return $query->whereHasMorph('claimable', [Invoice::class], fn (Builder $q) => $q->whereIn('clinic_id', $clinicIds));
        }

        if ($user->isDoctor() && $user->doctorProfile) {
            $doctorId = $user->doctorProfile->id;

            return $query->whereHasMorph('claimable', [Invoice::class], fn (Builder $q) => $q->whereHas('appointment', fn (Builder $a) => $a->where('doctor_profile_id', $doctorId)));
        }

        return $query->whereRaw('1 = 0');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->isSuperAdmin() || auth()->user()?->isClinicAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return ClaimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClaimsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaims::route('/'),
            'edit' => EditClaim::route('/{record}/edit'),
        ];
    }
}
