<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->enum('availability', ['offline', 'available', 'busy'])->default('offline')->after('is_accepting_appointments');
            $table->decimal('current_lat', 10, 7)->nullable()->after('availability');
            $table->decimal('current_lng', 10, 7)->nullable()->after('current_lat');
            $table->timestamp('location_updated_at')->nullable()->after('current_lng');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropColumn(['availability', 'current_lat', 'current_lng', 'location_updated_at']);
        });
    }
};
