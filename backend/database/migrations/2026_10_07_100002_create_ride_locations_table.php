<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Driver GPS breadcrumbs while a ride is active: live car position history + admin route replay. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('heading', 5, 1)->nullable();
            $table->decimal('speed_kmh', 6, 1)->nullable();
            $table->string('phase', 20)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->index(['ride_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_locations');
    }
};
