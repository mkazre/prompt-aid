<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Notify me when a doctor is online" rows left behind by unfilled requests. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_match_waitlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_match_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('specialty');
            $table->string('mode', 20);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->enum('status', ['waiting', 'notified', 'cancelled', 'expired'])->default('waiting');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'specialty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_match_waitlist');
    }
};
