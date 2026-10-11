<?php

use App\Support\ShuttleSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Seeds every shuttle setting default (idempotent: never overwrites an admin's value). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (ShuttleSettings::DEFAULTS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                Cache::forget("setting.{$key}");
            }
        }

        // Secret for the optional /tick?key= pinger URL (cron-free background jobs).
        if (! DB::table('settings')->where('key', 'tick_secret_key')->exists()) {
            DB::table('settings')->insert(['key' => 'tick_secret_key', 'value' => \Illuminate\Support\Str::random(40), 'created_at' => now(), 'updated_at' => now()]);
            Cache::forget('setting.tick_secret_key');
        }
    }

    public function down(): void
    {
        // Settings are left in place deliberately.
    }
};
