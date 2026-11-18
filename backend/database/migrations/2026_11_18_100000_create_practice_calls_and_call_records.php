<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orange "practice call" flow + call records:
 *
 *  - practice_staff:              practice reception team (users with role practice_reception) per clinic.
 *  - call_logs:                   every in-app voice / video call (practice ↔ patient calls and consult-hub
 *                                 video / voice consults): parties, timestamps, who answered / ended, outcome,
 *                                 per-participant join/leave evidence.
 *  - call_recordings (+ access log): recordings stored on Bunny.net Storage or the private local disk.
 *  - doctor_match_requests.flow:  'practice' when the scenario hands the accepted request to a practice call.
 *  - doctor_match_offers.clinic_id / target: one offer per practice, answered by its reception team.
 *
 * Additive and idempotent (hasTable / hasColumn guards). The role enum on users is a
 * VARCHAR on MySQL since 2026_09_13 (no change needed for 'practice_reception').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('practice_staff')) {
            Schema::create('practice_staff', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 30)->default('reception');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['clinic_id', 'user_id']);
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('call_logs')) {
            Schema::create('call_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('kind', 10)->default('voice');            // voice | video
                $table->string('source', 30)->default('practice_call');   // practice_call | consult_hub
                $table->string('status', 20)->default('ringing');        // ringing | connected | ended | declined | missed | cancelled
                $table->unsignedBigInteger('doctor_match_request_id')->nullable()->index();
                $table->unsignedBigInteger('appointment_id')->nullable()->index();
                $table->unsignedBigInteger('consult_session_id')->nullable()->index();
                $table->unsignedBigInteger('clinic_id')->nullable()->index();
                $table->unsignedBigInteger('doctor_profile_id')->nullable()->index();
                $table->unsignedBigInteger('patient_profile_id')->nullable()->index();
                $table->unsignedBigInteger('patient_user_id')->nullable();
                $table->unsignedBigInteger('practice_user_id')->nullable()->index();
                $table->unsignedBigInteger('started_by_user_id')->nullable();
                $table->string('started_by_role', 20)->nullable();
                $table->string('room', 120)->nullable()->index();
                $table->unsignedSmallInteger('ring_attempts')->default(0);
                $table->timestamp('ringing_at')->nullable();
                $table->timestamp('practice_accepted_at')->nullable();
                $table->timestamp('patient_accepted_at')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->string('declined_by', 20)->nullable();
                $table->unsignedBigInteger('declined_by_user_id')->nullable();
                $table->unsignedBigInteger('ended_by_user_id')->nullable();
                $table->string('ended_by_role', 20)->nullable();
                $table->string('outcome', 40)->nullable();
                $table->string('end_reason', 255)->nullable();
                $table->json('participants')->nullable();
                $table->json('meta')->nullable();
                $table->string('recording_status', 20)->default('none');
                $table->timestamps();
                $table->index(['status', 'ringing_at']);
                $table->index(['clinic_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('call_recordings')) {
            Schema::create('call_recordings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
                $table->string('source', 20)->default('client');       // jaas | client
                $table->string('status', 20)->default('receiving');    // receiving | processing | stored | failed | deleted
                $table->string('disk', 20)->nullable();                // bunny | local
                $table->string('path', 500)->nullable();
                $table->string('mime', 80)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('sha256', 64)->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->unsignedInteger('chunk_count')->default(0);
                $table->string('external_id', 190)->nullable()->index();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamp('retention_until')->nullable()->index();
                $table->boolean('legal_hold')->default(false);
                $table->string('hold_reason', 255)->nullable();
                $table->unsignedBigInteger('held_by')->nullable();
                $table->timestamp('held_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->string('error', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('call_recording_access_logs')) {
            Schema::create('call_recording_access_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('call_recording_id')->constrained('call_recordings')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('action', 20); // play | download | hold | release | delete | denied
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('doctor_match_requests') && ! Schema::hasColumn('doctor_match_requests', 'flow')) {
            Schema::table('doctor_match_requests', function (Blueprint $table) {
                $table->string('flow', 20)->nullable()->after('scenario_level');
            });
        }

        if (Schema::hasTable('doctor_match_offers')) {
            Schema::table('doctor_match_offers', function (Blueprint $table) {
                if (! Schema::hasColumn('doctor_match_offers', 'clinic_id')) {
                    $table->unsignedBigInteger('clinic_id')->nullable()->index();
                }
                if (! Schema::hasColumn('doctor_match_offers', 'target')) {
                    $table->string('target', 20)->nullable();
                }
            });
        }

        $this->seedPermissions();
    }

    /** New permission keys for the system roles + a "Practice reception" system role. Insert-if-missing only. */
    protected function seedPermissions(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }
        $grant = function (?int $roleId, array $keys) {
            if (! $roleId) {
                return;
            }
            foreach ($keys as $key) {
                if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_key', $key)->exists()) {
                    DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        };

        $all = ['call-logs.view', 'call-recordings.play', 'call-recordings.manage', 'practice-calls.handle', 'practice-call-settings.manage', 'recording-storage.manage'];
        $grant(DB::table('roles')->where('slug', 'super-admin')->value('id'), $all);
        $practice = ['call-logs.view', 'call-recordings.play', 'practice-calls.handle'];
        $grant(DB::table('roles')->where('slug', 'clinic-admin')->value('id'), $practice);
        $grant(DB::table('roles')->where('slug', 'doctor')->value('id'), $practice);

        $receptionId = DB::table('roles')->where('slug', 'practice-reception')->value('id');
        if (! $receptionId) {
            $receptionId = DB::table('roles')->insertGetId(['name' => 'Practice reception', 'slug' => 'practice-reception', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $grant((int) $receptionId, array_merge($practice, [
            'appointments.view', 'appointments.create', 'appointments.edit', 'patient-profiles.view',
        ]));
    }

    public function down(): void
    {
        if (Schema::hasTable('doctor_match_offers')) {
            Schema::table('doctor_match_offers', function (Blueprint $table) {
                foreach (['clinic_id', 'target'] as $c) {
                    if (Schema::hasColumn('doctor_match_offers', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('doctor_match_requests') && Schema::hasColumn('doctor_match_requests', 'flow')) {
            Schema::table('doctor_match_requests', fn (Blueprint $t) => $t->dropColumn('flow'));
        }
        Schema::dropIfExists('call_recording_access_logs');
        Schema::dropIfExists('call_recordings');
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('practice_staff');
    }
};
