<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * On-demand video consults can be billed by a doctor who isn't linked to
 * any clinic, so an invoice's clinic becomes optional (the FK stays).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE invoices MODIFY clinic_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Not reversed: rows with a NULL clinic may exist by then.
    }
};
