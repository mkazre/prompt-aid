<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consult hub: one row per appointment that reached the hub (doctor
 * accepted an on-demand consult, or a booked video / call-back consult was
 * opened). Records which channels were used (video, in-app voice, chat,
 * phone, WhatsApp) with start/end times, total connected seconds, who
 * ended it and the outcome — for billing disputes and doctor SLA.
 *
 * connected_at / ended_at double as the "fire ConsultStarted / ConsultEnded
 * exactly once" guards (set with a conditional UPDATE … WHERE … IS NULL).
 *
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consult_sessions')) {
            return;
        }

        Schema::create('consult_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id')->unique();
            $table->string('current_channel', 12)->nullable();
            $table->string('ring_channel', 8)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->string('first_channel', 12)->nullable();
            $table->unsignedInteger('connected_seconds')->default(0);
            $table->json('channels')->nullable();
            $table->timestamp('doctor_seen_at')->nullable();
            $table->string('doctor_channel', 8)->nullable();
            $table->timestamp('patient_seen_at')->nullable();
            $table->string('patient_channel', 8)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('ended_by_user_id')->nullable();
            $table->string('ended_by_role', 12)->nullable();
            $table->string('outcome', 24)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('connected_at');
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consult_sessions');
    }
};
