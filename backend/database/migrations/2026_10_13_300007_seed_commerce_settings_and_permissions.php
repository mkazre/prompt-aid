<?php

use App\Support\CommerceSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the commerce/alerts settings (insert-if-missing, never overwrites
 * admin edits) and grants the new Staff keys to the super-admin system
 * role. Accounts without a role_id use Permissions::defaultsForEnumRole().
 */
return new class extends Migration
{
    protected array $keys = [
        'promo-codes.view', 'promo-codes.create', 'promo-codes.edit', 'promo-codes.delete',
        'doctor-delay-notices.view', 'doctor-delay-notices.create', 'doctor-delay-notices.edit', 'doctor-delay-notices.delete',
        'referrals.view', 'commerce-settings.manage', 'expiring-documents.view',
    ];

    public function up(): void
    {
        foreach (CommerceSettings::DEFAULTS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
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
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(CommerceSettings::DEFAULTS))->delete();

        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_key', $this->keys)->delete();
        }
    }
};
