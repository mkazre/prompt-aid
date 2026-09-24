<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Gates a resource's nav visibility + CRUD actions behind
 * User::hasPermission(), on top of (never instead of) whatever tenant
 * scoping the resource's getEloquentQuery() already applies (e.g.
 * ScopesToClinicOrDoctor). A resource using this trait must implement
 * permissionKey() to say which `{prefix}.*` keys in App\Support\Permissions
 * it's gated by. Resources that already define their own canViewAny() etc.
 * (e.g. for business rules unrelated to permissions, like "never
 * deletable") simply override the trait's method as normal — PHP resolves
 * the class's own method over the trait's.
 */
trait ChecksPermissions
{
    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->hasPermission(static::permissionKey().'.create');
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->hasPermission(static::permissionKey().'.edit');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->hasPermission(static::permissionKey().'.delete');
    }
}
