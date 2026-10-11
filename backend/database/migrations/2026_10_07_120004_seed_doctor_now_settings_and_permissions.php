<?php

use App\Support\DoctorNowSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the "See a doctor now" settings (insert-if-missing, never overwrites
 * admin edits) and grants the new Staff keys to the super-admin system role.
 */
return new class extends Migration
{
    protected array $keys = [
        'doctor-matches.view', 'doctor-matches.create', 'doctor-matches.edit', 'doctor-matches.delete',
        'doctor-now-settings.manage',
    ];

    public function up(): void
    {
        foreach (DoctorNowSettings::DEFAULTS as $key => $value) {
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
        DB::table('settings')->whereIn('key', array_keys(DoctorNowSettings::DEFAULTS))->delete();

        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_key', $this->keys)->delete();
        }
    }
};
