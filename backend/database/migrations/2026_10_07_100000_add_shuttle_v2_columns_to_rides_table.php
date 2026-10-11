<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shuttle v2 (Bolt/Uber-grade): trip PIN, share link, sequential-offer
 * dispatch state, locked upfront quote + fare breakdown, planned road
 * route, cancellation/no-show bookkeeping, driver-to-patient rating and the
 * admin-only TEST simulator state. All additive + nullable/defaulted, so it
 * is safe to run before the code deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('trip_pin', 6)->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->string('dispatch_state', 20)->nullable();
            $table->timestamp('dispatch_started_at')->nullable();
            $table->unsignedSmallInteger('dispatch_attempts')->default(0);
            $table->decimal('quoted_fare', 10, 2)->nullable();
            $table->decimal('surge_multiplier', 5, 2)->nullable();
            $table->string('quote_token', 64)->nullable();
            $table->timestamp('quote_expires_at')->nullable();
            $table->longText('fare_breakdown')->nullable();
            $table->decimal('route_distance_km', 8, 2)->nullable();
            $table->unsignedInteger('route_duration_min')->nullable();
            $table->longText('route_geometry')->nullable();
            $table->timestamp('eta_updated_at')->nullable();
            $table->timestamp('driver_arrived_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->decimal('cancellation_fee', 10, 2)->nullable();
            $table->string('cancellation_fee_status', 20)->nullable();
            $table->unsignedTinyInteger('patient_rating')->nullable();
            $table->text('patient_rating_comment')->nullable();
            $table->text('simulation')->nullable();
            $table->index(['status', 'dispatch_state'], 'rides_status_dispatch_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_status_dispatch_state_index');
            $table->dropUnique(['share_token']);
            $table->dropColumn([
                'trip_pin', 'share_token', 'dispatch_state', 'dispatch_started_at', 'dispatch_attempts',
                'quoted_fare', 'surge_multiplier', 'quote_token', 'quote_expires_at', 'fare_breakdown',
                'route_distance_km', 'route_duration_min', 'route_geometry', 'eta_updated_at',
                'driver_arrived_at', 'cancelled_at', 'cancelled_by', 'cancellation_fee',
                'cancellation_fee_status', 'patient_rating', 'patient_rating_comment', 'simulation',
            ]);
        });
    }
};
