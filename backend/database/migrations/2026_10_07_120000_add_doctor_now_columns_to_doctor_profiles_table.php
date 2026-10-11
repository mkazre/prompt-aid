<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "See a doctor now" presence + fairness columns. last_seen_at is bumped by
 * the doctor console heartbeat (stale doctors are never offered requests);
 * last_offered_at / last_assigned_at drive the least-recently-served order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('location_updated_at');
            $table->timestamp('last_offered_at')->nullable()->after('last_seen_at');
            $table->timestamp('last_assigned_at')->nullable()->after('last_offered_at');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['last_seen_at', 'last_offered_at', 'last_assigned_at']);
        });
    }
};
