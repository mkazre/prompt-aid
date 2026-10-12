<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform stream: feature switches (all ON), the accessibility toolbar
 * toggle (ON), login-history retention, and the new Staff permission keys
 * for the super-admin system role. Insert-if-missing only — never
 * overwrites a value an admin already saved.
 */
return new class extends Migration
{
    protected array $settings = [
        'feature_shop' => '1',
        'feature_shuttle' => '1',
        'feature_video' => '1',
        'feature_doctor_now' => '1',
        'feature_testimonials' => '1',
        'feature_articles' => '1',
        'feature_topic' => '1',
        'feature_labs' => '1',
        'a11y_toolbar_enabled' => '1',
        'login_events_retention_days' => '180',
    ];

    protected array $keys = [
        'reports.view',
        'system-health.view',
        'features.manage',
        'login-events.view', 'login-events.create', 'login-events.edit', 'login-events.delete',
    ];

    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            foreach ($this->settings as $key => $value) {
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
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_key', $this->keys)->delete();
        }
    }
};
