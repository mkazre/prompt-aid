<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * driver_profiles.vehicle_type was never widened when ride_rate_cards got
 * 'stretcher' (2026_09_23_100017), so a stretcher vehicle could not be
 * registered and stretcher rides could never be matched. MODIFY in place
 * keeps every existing row's value. MySQL/MariaDB only (production); the
 * local SQLite dev DB keeps its original CHECK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE driver_profiles MODIFY vehicle_type ENUM('sedan', 'suv', 'van', 'wheelchair_accessible', 'stretcher') NOT NULL DEFAULT 'sedan'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE driver_profiles SET vehicle_type = 'van' WHERE vehicle_type = 'stretcher'");
            DB::statement("ALTER TABLE driver_profiles MODIFY vehicle_type ENUM('sedan', 'suv', 'van', 'wheelchair_accessible') NOT NULL DEFAULT 'sedan'");
        }
    }
};
