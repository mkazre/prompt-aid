<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one time-off row cover a date range (a holiday) instead of one row
 * per day. Null end_date means a single day, so existing rows are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_time_off', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_time_off', function (Blueprint $table) {
            $table->dropColumn('end_date');
        });
    }
};
