<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sequential offers of a match request to one doctor at a time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_match_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_match_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['offered', 'accepted', 'declined', 'expired'])->default('offered');
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['doctor_profile_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_match_offers');
    }
};
