<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per on-demand ("See a doctor now") request — the matching state
 * machine that sits beside its Appointment (status `matching` until a
 * doctor accepts). The price is locked here at request time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_match_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->string('specialty');
            $table->string('mode', 20);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->enum('status', ['searching', 'matched', 'expired', 'cancelled'])->default('searching');
            $table->string('outcome_reason')->nullable();
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('multiplier', 5, 2)->default(1);
            $table->decimal('price', 10, 2)->default(0);
            $table->text('price_breakdown')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('doctor_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('cancel_fee', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['status', 'specialty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_match_requests');
    }
};
