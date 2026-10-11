<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Live heading/speed for the rotating car marker + reliability counters used by dispatch ranking. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->decimal('current_heading', 5, 1)->nullable();
            $table->decimal('current_speed_kmh', 6, 1)->nullable();
            $table->unsignedInteger('offers_accepted_count')->default(0);
            $table->unsignedInteger('offers_declined_count')->default(0);
            $table->unsignedInteger('offers_missed_count')->default(0);
            $table->unsignedInteger('rides_cancelled_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'current_heading', 'current_speed_kmh', 'offers_accepted_count',
                'offers_declined_count', 'offers_missed_count', 'rides_cancelled_count',
            ]);
        });
    }
};
