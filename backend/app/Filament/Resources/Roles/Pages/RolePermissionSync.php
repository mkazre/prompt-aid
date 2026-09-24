<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\Role;
use App\Support\Permissions;

/**
 * Bridges the RoleForm's per-group `perm__*` CheckboxList fields (there's
 * no single form field a Role's permissions can bind to directly) and the
 * role_permissions pivot table.
 */
class RolePermissionSync
{
    /**
     * Pulls every `perm__*` field out of the submitted form data (mutating
     * $data in place so it's safe to mass-assign to the Role afterwards)
     * and returns the flattened, deduped list of granted permission keys.
     *
     * @return array<int, string>
     */
    public static function extract(array &$data): array
    {
        $keys = [];

        foreach (array_keys(Permissions::grouped()) as $group) {
            $field = RoleForm::fieldNameForGroup($group);
            $keys = array_merge($keys, $data[$field] ?? []);
            unset($data[$field]);
        }

        return array_values(array_unique($keys));
    }

    public static function apply(Role $role, array $permissionKeys): void
    {
        $role->permissions()->delete();

        if ($permissionKeys !== []) {
            $role->permissions()->createMany(
                array_map(fn (string $key) => ['permission_key' => $key], $permissionKeys)
            );
        }
    }

    /**
     * @return array<string, array<int, string>> perm__group-slug => granted keys in that group, for form fill
     */
    public static function toFormData(Role $role): array
    {
        $granted = $role->permissionKeys();
        $data = [];

        foreach (Permissions::grouped() as $group => $keys) {
            $data[RoleForm::fieldNameForGroup($group)] = array_values(array_intersect($keys, $granted));
        }

        return $data;
    }
}
