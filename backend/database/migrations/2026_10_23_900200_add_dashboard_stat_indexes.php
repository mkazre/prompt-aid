<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the live landing-dashboard aggregates (App\Services\Dashboard\
 * DashboardStatsService): status/date filters on the busiest tables.
 * Additive and idempotent — each index is skipped if it (or the column) is missing
 * or already exists, so re-running on any host is safe.
 */
return new class extends Migration
{
    /** @var array<string, array<string, array<int, string>>> table => [index name => columns] */
    protected array $indexes = [
        'orders' => ['dash_orders_status_created_idx' => ['status', 'created_at'], 'dash_orders_pharmacy_status_idx' => ['pharmacy_id', 'status']],
        'lab_requests' => ['dash_lab_status_tp_idx' => ['status', 'third_party_profile_id'], 'dash_lab_tp_completed_idx' => ['third_party_profile_id', 'completed_at']],
        'appointments' => ['dash_appt_date_status_idx' => ['date', 'status'], 'dash_appt_doctor_date_idx' => ['doctor_profile_id', 'date']],
        'rides' => ['dash_rides_created_idx' => ['created_at'], 'dash_rides_driver_status_idx' => ['driver_profile_id', 'status', 'completed_at']],
        'doctor_match_requests' => ['dash_dmr_created_idx' => ['created_at']],
        'dispatch_jobs' => ['dash_jobs_created_idx' => ['created_at', 'service']],
        'prescription_uploads' => ['dash_scripts_status_idx' => ['status']],
        'payments' => ['dash_payments_status_created_idx' => ['status', 'created_at']],
        'ride_charges' => ['dash_ride_charges_status_idx' => ['status', 'updated_at']],
        'driver_documents' => ['dash_driver_docs_expires_idx' => ['expires_at']],
        'dispatch_offers' => ['dash_offers_provider_offered_idx' => ['provider_user_id', 'offered_at']],
        'doctor_match_offers' => ['dash_dmo_doctor_offered_idx' => ['doctor_profile_id', 'offered_at']],
        'ride_offers' => ['dash_ride_offers_driver_offered_idx' => ['driver_profile_id', 'offered_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $list) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($list as $name => $cols) {
                if (! Schema::hasColumns($table, $cols) || Schema::hasIndex($table, $name)) {
                    continue;
                }
                try {
                    Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
                } catch (\Throwable $e) {
                    // Never block a deploy over an optional performance index.
                    report($e);
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $list) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($list) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
