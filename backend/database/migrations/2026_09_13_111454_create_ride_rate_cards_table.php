<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->enum('vehicle_type', ['sedan', 'suv', 'van', 'wheelchair_accessible'])->unique();
            $table->decimal('base_fare', 8, 2)->default(30);
            $table->decimal('per_km_rate', 8, 2)->default(12);
            $table->decimal('per_minute_rate', 8, 2)->default(0);
            $table->decimal('minimum_fare', 8, 2)->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_rate_cards');
    }
};
