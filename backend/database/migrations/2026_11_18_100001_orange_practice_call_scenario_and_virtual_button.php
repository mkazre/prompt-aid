<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient request scenarios, Nov 2026 (client decision):
 *  - ORANGE: the request goes to every available GP practice; the first to
 *    accept calls the patient in-app and books in person / video (optional
 *    shuttle). Saved as a NEW version — the previous one stays restorable in
 *    Staff → Triage → Patient request scenarios.
 *  - BLUE "Virtual / video consult" button ('virtual'): the former orange
 *    page (choose video consult or call back, then broadcast). Inserted if missing.
 * Consult payments are switched off by default (PaymentSettings::consult_payments_enabled).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('triage_scenarios') || ! Schema::hasTable('triage_scenario_versions')) {
            return;
        }
        $defaults = \App\Services\Triage\ScenarioEngine::defaults();
        foreach (['orange' => 'Practice call flow (Nov 2026): first practice to accept calls the patient in-app and books the visit', 'virtual' => 'Blue "Virtual / video consult" button (former orange page)'] as $level => $note) {
            $json = json_encode($defaults[$level]);
            $row = DB::table('triage_scenarios')->where('level', $level)->first();
            if ($row && $level === 'virtual') {
                continue;
            }
            if ($row) {
                $current = json_decode((string) $row->steps, true) ?: [];
                if (collect($current)->contains(fn ($s) => ($s['type'] ?? null) === 'practice_call')) {
                    continue; // already on the practice-call flow
                }
                $version = (int) $row->version + 1;
                DB::table('triage_scenarios')->where('id', $row->id)->update(['steps' => $json, 'version' => $version, 'is_active' => true, 'updated_at' => now()]);
                $id = $row->id;
            } else {
                $version = 1;
                $id = DB::table('triage_scenarios')->insertGetId(['level' => $level, 'steps' => $json, 'version' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('triage_scenario_versions')->insert([
                'triage_scenario_id' => $id, 'level' => $level, 'version' => $version, 'steps' => $json, 'note' => $note, 'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                Cache::forget('triage_scenario.v1.'.$level);
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        // Versions are history; restore an older ORANGE version from the admin page instead.
    }
};
