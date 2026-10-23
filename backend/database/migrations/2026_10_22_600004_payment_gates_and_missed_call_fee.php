<?php

use App\Support\PaymentSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gates (client decisions, Oct 2026):
 *  - appointments.last_call_attempt_at: span of call-back attempts (missed call-back fee rule)
 *  - appointments.payment_state: booked appointment awaiting its booking payment (slot released if unpaid)
 *  - rides.dispatch_after_payment: dispatch state to start once the upfront hold is secured
 *  - new settings (insert-if-missing) + medical-aid co-pay default R0 → R100 ONLY where still '0'
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                if (! Schema::hasColumn('appointments', 'last_call_attempt_at')) {
                    $table->timestamp('last_call_attempt_at')->nullable();
                }
                if (! Schema::hasColumn('appointments', 'payment_state')) {
                    $table->string('payment_state', 20)->nullable();
                }
            });
        }
        if (Schema::hasTable('rides') && ! Schema::hasColumn('rides', 'dispatch_after_payment')) {
            Schema::table('rides', function (Blueprint $table) {
                $table->string('dispatch_after_payment', 20)->nullable();
            });
        }

        foreach (PaymentSettings::DEFAULTS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        // Co-pay default changed from R0 to R100: only touch rows nobody edited.
        DB::table('settings')->where('key', 'consult_medical_aid_copay')->whereIn('value', ['0', '0.00', ''])
            ->update(['value' => '100', 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::forget('setting.consult_medical_aid_copay');
    }

    public function down(): void
    {
        if (Schema::hasColumn('rides', 'dispatch_after_payment')) {
            Schema::table('rides', fn (Blueprint $t) => $t->dropColumn('dispatch_after_payment'));
        }
        foreach (['last_call_attempt_at', 'payment_state'] as $c) {
            if (Schema::hasColumn('appointments', $c)) {
                Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
