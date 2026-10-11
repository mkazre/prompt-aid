<?php

use App\Support\PrivacySettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Seeds every POPIA/privacy setting default (idempotent: never overwrites an admin's value). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PrivacySettings::DEFAULTS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                Cache::forget("setting.{$key}");
            }
        }
    }

    public function down(): void
    {
        // Settings are left in place deliberately.
    }
};
