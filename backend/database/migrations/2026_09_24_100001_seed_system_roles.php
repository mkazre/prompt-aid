<?php

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds one Role row per legacy User::role enum value (is_system = true)
 * with the same default permission set App\Support\Permissions::
 * defaultsForEnumRole() grants a user whose `role_id` is still null, so
 * a super_admin can start from a sane baseline in the RoleResource
 * instead of an empty checklist. Existing users keep working unchanged
 * either way, since role_id is nullable and the fallback in
 * User::hasPermission() covers them regardless of whether this ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super-admin', 'enum' => 'super_admin'],
            ['name' => 'Clinic Admin', 'slug' => 'clinic-admin', 'enum' => 'clinic_admin'],
            ['name' => 'Doctor', 'slug' => 'doctor', 'enum' => 'doctor'],
            ['name' => 'Driver', 'slug' => 'driver', 'enum' => 'driver'],
            ['name' => 'Patient', 'slug' => 'patient', 'enum' => 'patient'],
            ['name' => 'Third Party Partner', 'slug' => 'third-party', 'enum' => 'third_party'],
            ['name' => 'Pharmacy Admin', 'slug' => 'pharmacy-admin', 'enum' => 'pharmacy_admin'],
        ];

        foreach ($roles as $definition) {
            $role = Role::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'is_system' => true]
            );

            $defaults = Permissions::defaultsForEnumRole($definition['enum']);
            $keys = $defaults === '*' ? Permissions::all() : $defaults;

            $role->permissions()->delete();

            if ($keys !== []) {
                $role->permissions()->createMany(
                    array_map(fn (string $key) => ['permission_key' => $key], $keys)
                );
            }
        }
    }

    public function down(): void
    {
        Role::query()->where('is_system', true)->delete();
    }
};
