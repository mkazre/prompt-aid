<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class UserResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'users';
    }

    protected static ?string $model = User::class;

    // Creating staff accounts / changing roles & passwords is a
    // super_admin function — clinic_admin/doctor manage their clinic's
    // people via DoctorProfileResource/PatientProfileResource instead,
    // which are scoped to their own clinic and don't expose auth data.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Users & Access';

    // Creating/editing/deleting staff accounts (including who gets which
    // role) is a super_admin power regardless of what a custom Role might
    // later be checked to grant — never let ChecksPermissions' plain
    // hasPermission() check decide this on its own.
    public static function canViewAny(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.create');
    }

    public static function canEdit($record): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.edit');
    }

    public static function canDelete($record): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && (bool) auth()->user()?->hasPermission(static::permissionKey().'.delete');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
