<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequential driver offers (Bolt/Uber style): one pending offer per ride at
 * a time, expiring after the admin-configured timeout, then the next best
 * driver. Progressed lazily on polls (no queue worker / websockets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->unsignedInteger('eta_minutes')->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['ride_id', 'status']);
            $table->index(['driver_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_offers');
    }
};
