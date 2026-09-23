<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defensive cleanup from an earlier failed attempt at this migration
        // (MySQL DDL isn't transactional, so a partial run can leave this
        // column behind even though the migration itself didn't complete).
        if (Schema::hasColumn('ride_rate_cards', 'vehicle_type_tmp')) {
            Schema::table('ride_rate_cards', function ($table) {
                $table->dropColumn('vehicle_type_tmp');
            });
        }

        // Widening an enum's value list in place (MODIFY, not drop/recreate)
        // keeps every existing row's value untouched — no temp column, no
        // window where rows collide on a shared default.
        DB::statement("ALTER TABLE ride_rate_cards MODIFY vehicle_type ENUM('sedan', 'suv', 'van', 'wheelchair_accessible', 'stretcher') NOT NULL");

        DB::table('ride_rate_cards')->updateOrInsert(
            ['vehicle_type' => 'stretcher'],
            ['base_fare' => 60, 'per_km_rate' => 46, 'per_minute_rate' => 0, 'minimum_fare' => 350, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('ride_rate_cards')->where('vehicle_type', 'stretcher')->delete();
        DB::statement("ALTER TABLE ride_rate_cards MODIFY vehicle_type ENUM('sedan', 'suv', 'van', 'wheelchair_accessible') NOT NULL");
    }
};
