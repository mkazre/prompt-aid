<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Specialty the patient asked for, captured at request time since
            // doctor_profile_id is still null until a doctor is matched.
            $table->string('requested_specialty')->nullable()->after('mode');
        });

        // doctor_profile_id/clinic_id are unset while an on-demand match
        // request is awaiting a doctor; status/enum values are kept in one
        // ALTER so this migration is a straight copy-paste to raw SQL.
        DB::statement('ALTER TABLE appointments MODIFY doctor_profile_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE appointments MODIFY clinic_id BIGINT UNSIGNED NULL');
        DB::statement("ALTER TABLE appointments MODIFY status ENUM('pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show', 'matching') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE appointments MODIFY status ENUM('pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'pending'");
        DB::statement('ALTER TABLE appointments MODIFY clinic_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE appointments MODIFY doctor_profile_id BIGINT UNSIGNED NOT NULL');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('requested_specialty');
        });
    }
};
