<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grants the new Staff-panel keys to the seeded system roles so accounts
 * with a role_id see the Messages resource (doctors: view + reply; super
 * admin: everything). Accounts without a role_id are covered by
 * Permissions::defaultsForEnumRole().
 */
return new class extends Migration
{
    protected array $grants = [
        'doctor' => ['conversations.view', 'conversations.create'],
        'super-admin' => ['conversations.view', 'conversations.create', 'conversations.edit', 'conversations.delete', 'broadcast-notifications.send'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        foreach ($this->grants as $slug => $keys) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($keys as $key) {
                $exists = DB::table('role_permissions')->where('role_id', $roleId)->where('permission_key', $key)->exists();
                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_key' => $key,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_permissions')) {
            return;
        }

        DB::table('role_permissions')
            ->whereIn('permission_key', ['conversations.view', 'conversations.create', 'conversations.edit', 'conversations.delete', 'broadcast-notifications.send'])
            ->delete();
    }
};
