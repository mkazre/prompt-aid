<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('vehicle_type')->default('sedan')->after('appointment_id');
            $table->unsignedInteger('eta_minutes')->nullable()->after('distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['vehicle_type', 'eta_minutes']);
        });
    }
};
