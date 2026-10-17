<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified dispatch defaults (insert-if-missing — never overwrites an admin
 * value) and the two new Staff permission keys for the super-admin role.
 * Provider roles (doctor, driver, pharmacy, partner) need NO permission for
 * the incoming-request alert: it is part of every panel for those roles and
 * every endpoint is scoped to the signed-in provider.
 */
return new class extends Migration
{
    protected array $keys = ['dispatch-settings.manage', 'live-requests.view'];

    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            foreach (\App\Support\DispatchSettings::DEFAULTS as $key => $value) {
                if (! DB::table('settings')->where('key', $key)->exists()) {
                    DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }
        $roleId = DB::table('roles')->where('slug', 'super-admin')->value('id');
        if (! $roleId) {
            return;
        }
        foreach ($this->keys as $key) {
            if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_key', $key)->exists()) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_key', $this->keys)->delete();
        }
    }
};
