<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PromptAid Emergency Scale (client amendments, Oct 2026):
 * - orange "Within 10 minutes" → "Within 30 minutes"
 * - yellow "Within 60 minutes" → "Within 8 hours"
 * - green  "Within 4 hours"    → "In the next few days"
 * - the PromptAid dispatch centre becomes the primary emergency number.
 * Data-only; leaves every other admin edit to the triage config alone.
 */
return new class extends Migration
{
    protected const TARGETS = [
        'orange' => [30, 'Within 30 minutes'],
        'yellow' => [480, 'Within 8 hours'],
        'green' => [4320, 'In the next few days'],
    ];

    public function up(): void
    {
        $row = DB::table('triage_configs')->where('key', 'meta')->first();

        if ($row) {
            $meta = json_decode($row->value, true) ?: [];

            foreach (self::TARGETS as $level => [$target, $label]) {
                if (isset($meta[$level])) {
                    $meta[$level]['target'] = $target;
                    $meta[$level]['targetLabel'] = $label;
                }
            }

            DB::table('triage_configs')->where('key', 'meta')->update(['value' => json_encode($meta), 'updated_at' => now()]);
            Cache::forget('triage-config');
        }

        $exists = DB::table('emergency_contacts')->where('tel_url', 'tel:0816709522')->exists();

        if (! $exists) {
            DB::table('emergency_contacts')->update(['is_primary' => false]);
            DB::table('emergency_contacts')->insert([
                'label' => 'PromptAid dispatch centre',
                'phone' => '081 670 9522',
                'tel_url' => 'tel:0816709522',
                'is_primary' => true,
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Cache::forget('emergency-contacts');
        }
    }

    public function down(): void
    {
        // Data migration — intentionally not reversed.
    }
};
