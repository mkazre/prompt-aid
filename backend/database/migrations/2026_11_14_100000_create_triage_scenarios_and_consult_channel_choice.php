<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient request scenarios (Staff → Triage → Patient request scenarios):
 * one ordered list of steps per triage level (red / orange / yellow /
 * green), versioned, plus the "choose the channel AFTER a doctor accepts"
 * columns on doctor_match_requests. Additive and idempotent (cPanel deploy
 * runs migrations, not seeders): the four recommended default scenarios
 * and the super-admin permission are inserted only when missing.
 */
return new class extends Migration
{
    protected array $keys = ['triage-scenarios.manage'];

    public function up(): void
    {
        if (! Schema::hasTable('triage_scenarios')) {
            Schema::create('triage_scenarios', function (Blueprint $table) {
                $table->id();
                // red | orange | yellow | green (room for "orange:<specialty>" later).
                $table->string('level', 60)->unique();
                $table->json('steps');
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_active')->default(true);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('triage_scenario_versions')) {
            Schema::create('triage_scenario_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('triage_scenario_id')->constrained('triage_scenarios')->cascadeOnDelete();
                $table->string('level', 60);
                $table->unsignedInteger('version');
                $table->json('steps');
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['triage_scenario_id', 'version']);
                $table->index(['level', 'version']);
            });
        }

        if (Schema::hasTable('doctor_match_requests')) {
            Schema::table('doctor_match_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('doctor_match_requests', 'scenario_level')) {
                    $table->string('scenario_level', 60)->nullable();
                }
                if (! Schema::hasColumn('doctor_match_requests', 'scenario_version')) {
                    $table->unsignedInteger('scenario_version')->nullable();
                }
                if (! Schema::hasColumn('doctor_match_requests', 'scenario')) {
                    // Snapshot of the consult settings the request was started with.
                    $table->json('scenario')->nullable();
                }
                if (! Schema::hasColumn('doctor_match_requests', 'channel_pending')) {
                    // True between "doctor accepted" and "patient chose video / voice / WhatsApp / …".
                    $table->boolean('channel_pending')->default(false);
                }
                if (! Schema::hasColumn('doctor_match_requests', 'chosen_channel')) {
                    $table->string('chosen_channel', 20)->nullable();
                }
                if (! Schema::hasColumn('doctor_match_requests', 'channel_chosen_at')) {
                    $table->timestamp('channel_chosen_at')->nullable();
                }
                if (! Schema::hasColumn('doctor_match_requests', 'channel_chosen_by')) {
                    $table->string('channel_chosen_by', 10)->nullable();
                }
            });
        }

        if (Schema::hasTable('doctor_profiles') && ! Schema::hasColumn('doctor_profiles', 'whatsapp_number')) {
            Schema::table('doctor_profiles', function (Blueprint $table) {
                // E.164 digits (27821234567): patients who pick "WhatsApp call" after accept open wa.me/<this>.
                $table->string('whatsapp_number', 20)->nullable();
            });
        }

        // Recommended defaults (never overwrites an admin's scenario).
        $now = now();
        foreach (\App\Services\Triage\ScenarioEngine::defaults() as $level => $steps) {
            if (DB::table('triage_scenarios')->where('level', $level)->exists()) {
                continue;
            }
            $json = json_encode($steps);
            $id = DB::table('triage_scenarios')->insertGetId([
                'level' => $level, 'steps' => $json, 'version' => 1, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('triage_scenario_versions')->insert([
                'triage_scenario_id' => $id, 'level' => $level, 'version' => 1, 'steps' => $json,
                'note' => 'Recommended default', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            $roleId = DB::table('roles')->where('slug', 'super-admin')->value('id');
            if ($roleId) {
                foreach ($this->keys as $key) {
                    if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_key', $key)->exists()) {
                        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_key' => $key, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_key', $this->keys)->delete();
        }
        if (Schema::hasTable('doctor_match_requests')) {
            Schema::table('doctor_match_requests', function (Blueprint $table) {
                foreach (['scenario_level', 'scenario_version', 'scenario', 'channel_pending', 'chosen_channel', 'channel_chosen_at', 'channel_chosen_by'] as $col) {
                    if (Schema::hasColumn('doctor_match_requests', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('doctor_profiles') && Schema::hasColumn('doctor_profiles', 'whatsapp_number')) {
            Schema::table('doctor_profiles', fn (Blueprint $table) => $table->dropColumn('whatsapp_number'));
        }
        Schema::dropIfExists('triage_scenario_versions');
        Schema::dropIfExists('triage_scenarios');
    }
};
