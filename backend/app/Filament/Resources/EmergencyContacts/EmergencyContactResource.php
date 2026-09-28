<?php

namespace App\Filament\Resources\EmergencyContacts;

use App\Filament\Resources\EmergencyContacts\Pages\CreateEmergencyContact;
use App\Filament\Resources\EmergencyContacts\Pages\EditEmergencyContact;
use App\Filament\Resources\EmergencyContacts\Pages\ListEmergencyContacts;
use App\Filament\Resources\EmergencyContacts\Schemas\EmergencyContactForm;
use App\Filament\Resources\EmergencyContacts\Tables\EmergencyContactsTable;
use App\Models\EmergencyContact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The phone numbers/labels/tel: links shown on the emergency triage flow,
 * the results page, the site-wide pre-triage modal and the contact page —
 * previously hardcoded in Blade/JS. Super-admin only, same reasoning as
 * RoleResource/AuditLogResource/NotificationLogResource: getting this wrong
 * means a patient calls the wrong number in an emergency.
 */
class EmergencyContactResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'emergency-contacts';
    }

    protected static ?string $model = EmergencyContact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Emergency Contacts';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.create');
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.edit');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.delete');
    }

    public static function form(Schema $schema): Schema
    {
        return EmergencyContactForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmergencyContactsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmergencyContacts::route('/'),
            'create' => CreateEmergencyContact::route('/create'),
            'edit' => EditEmergencyContact::route('/{record}/edit'),
        ];
    }
}
