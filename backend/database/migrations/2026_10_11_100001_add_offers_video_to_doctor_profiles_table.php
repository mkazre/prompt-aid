<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            // Every doctor could already be booked for video, so existing
            // rows default to true; a doctor/admin can switch it off.
            $table->boolean('offers_video')->default(true)->after('is_accepting_appointments');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropColumn('offers_video');
        });
    }
};
