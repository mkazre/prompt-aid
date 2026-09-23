<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->foreignId('ride_series_id')->nullable()->after('appointment_id')->constrained('ride_series')->nullOnDelete();
            $table->foreignId('return_of_ride_id')->nullable()->after('ride_series_id')->constrained('rides')->nullOnDelete();
            $table->boolean('is_return')->default(false)->after('return_of_ride_id');
            $table->timestamp('scheduled_for')->nullable()->after('is_return');
            $table->boolean('wait_and_return')->default(false)->after('scheduled_for');
            $table->enum('priority', ['normal', 'emergency'])->default('normal')->after('wait_and_return');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ride_series_id');
            $table->dropConstrainedForeignId('return_of_ride_id');
            $table->dropColumn(['is_return', 'scheduled_for', 'wait_and_return', 'priority']);
        });
    }
};
