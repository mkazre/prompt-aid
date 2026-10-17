<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Same idiom as EmergencyContactResource: super admin AND the granular
 * `{prefix}.{action}` permission. Resources set $readOnly = true to forbid
 * create/edit/delete entirely (payment intents, webhook events).
 */
trait SuperAdminPermissionGate
{
    protected static function allowed(string $action): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission(static::permissionKey().'.'.$action));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return static::allowed('view');
    }

    public static function canView($record): bool
    {
        return static::allowed('view');
    }

    public static function canCreate(): bool
    {
        return ! static::$readOnly && static::allowed('create');
    }

    public static function canEdit($record): bool
    {
        return ! static::$readOnly && static::allowed('edit');
    }

    public static function canDelete($record): bool
    {
        return ! static::$readOnly && static::allowed('delete');
    }

    public static function canDeleteAny(): bool
    {
        return ! static::$readOnly && static::allowed('delete');
    }
}
