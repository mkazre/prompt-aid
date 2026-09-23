<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->json('pattern'); // {days:[1,3,5], time:"09:00", until:"2026-12-31"}
            $table->json('pickup'); // {address, lat, lng}
            $table->json('dropoff'); // {address, lat, lng}
            $table->string('vehicle_type')->default('sedan');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_series');
    }
};
