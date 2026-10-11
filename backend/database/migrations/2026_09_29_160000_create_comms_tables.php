<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor–patient chat, in-app notifications and in-app video rooms.
 *
 * `conversations` is unique per (patient, doctor) pair rather than per
 * appointment: a care relationship is one continuous thread (follow-up
 * questions after a visit belong next to the pre-visit ones), and it avoids
 * a patient seeing five near-empty threads with the same GP. `appointment_id`
 * records the most recent appointment the thread was opened from, for the
 * "Consultation start" context card.
 *
 * `user_notifications` is deliberately separate from Laravel's
 * `notifications` table, which Filament already uses for the staff panel's
 * bell (StaffNotifier) — sharing it would mix the two feeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['patient_profile_id', 'doctor_profile_id']);
            $table->index('last_message_at');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'read_at']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('video_room', 80)->nullable()->unique()->after('meet_created_at');
            $table->timestamp('video_ended_at')->nullable()->after('video_room');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['video_room']);
            $table->dropColumn(['video_room', 'video_ended_at']);
        });
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
