<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trip audio recording settings (defaults identical to
 * App\Support\RideRecordingSettings::DEFAULTS) and the two new Staff
 * permission keys for the super-admin system role. Insert-if-missing only.
 */
return new class extends Migration
{
    protected array $settings = [
        'ride_recording_enabled_patient' => '1',
        'ride_recording_enabled_driver' => '1',
        'ride_recording_retention_days' => '3',
        'ride_recording_max_minutes' => '120',
        'ride_recording_chunk_seconds' => '30',
        'ride_recording_auto_hold' => '1',
    ];

    protected array $keys = ['ride-recordings.view', 'ride-recordings.manage'];

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
