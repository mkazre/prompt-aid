<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip audio recordings (safety evidence) for shuttle rides.
 *
 * Audio is stored ENCRYPTED on the private `local` disk under
 * ride-recordings/{ride}/{recording}/{seq}.enc — one file per uploaded
 * chunk; `segments` holds the manifest (seq, path, plaintext size,
 * sha256, duration). `sha256` is the SHA-256 of the full plaintext audio
 * (all chunks concatenated in sequence order), computed on completion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ride_recordings')) {
            Schema::create('ride_recordings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('role', 10); // driver | patient
                $table->string('source', 10)->default('app'); // app | web
                // stream   = chunks are continuation pieces of ONE file (web MediaRecorder): play/download concatenated
                // segments = each chunk is a standalone file (app recorder rotates every ~30 s): play in order
                $table->string('layout', 10)->default('segments');
                $table->string('disk', 20)->default('local');
                $table->string('path')->nullable(); // directory: ride-recordings/{ride}/{recording}
                $table->string('mime', 60);
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->unsignedInteger('chunk_count')->default(0);
                $table->json('segments')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamp('last_chunk_at')->nullable();
                $table->string('sha256', 64)->nullable();
                $table->string('status', 12)->default('uploading'); // uploading | complete | failed
                $table->string('end_reason', 40)->nullable();
                $table->timestamp('consent_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('legal_hold')->default(false);
                $table->string('hold_reason', 255)->nullable();
                $table->string('hold_source', 10)->nullable(); // auto | admin
                $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('held_at')->nullable();
                $table->timestamp('hold_released_at')->nullable();
                $table->foreignId('hold_released_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['ride_id', 'status']);
                $table->index(['legal_hold', 'expires_at']);
            });
        }

        if (! Schema::hasTable('ride_recording_access_logs')) {
            Schema::create('ride_recording_access_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ride_recording_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('actor_role', 20)->nullable(); // patient | driver | admin
                $table->string('action', 20); // play | download | hold | release | delete | purge
                $table->string('detail', 255)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['ride_recording_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_recording_access_logs');
        Schema::dropIfExists('ride_recordings');
    }
};
