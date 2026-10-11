<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Same gating idiom as EmergencyContactResource / AuditLogResource, as a
 * trait for the POPIA resources: super_admin only AND the granular
 * `{permissionKey}.*` key. A resource may still override any method
 * (e.g. read-only logs return false from canCreate/canEdit/canDelete).
 */
trait GatesSuperAdminPermissions
{
    abstract protected static function permissionKey(): string;

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
}
