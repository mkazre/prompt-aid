<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $permissionKeys = RolePermissionSync::extract($data);

        /** @var Role $role */
        $role = static::getModel()::create($data);

        RolePermissionSync::apply($role, $permissionKeys);

        return $role;
    }
}
