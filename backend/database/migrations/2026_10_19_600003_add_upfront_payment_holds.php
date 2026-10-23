<?php

use App\Support\PaymentSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upfront payments ("pay before we connect you"):
 *  - payment_intents: hold/capture lifecycle (capture_mode, captured_amount, authorized/captured/released timestamps)
 *  - doctor_match_requests: payment gate (payment_state, payment_intent_id, bill_medical_aid)
 *  - clinics / doctor_profiles: booking payment policy override (full / deposit / at_practice)
 *  - rides: link to the upfront hold
 *  - new payment settings (insert-if-missing)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_intents') && ! Schema::hasColumn('payment_intents', 'capture_mode')) {
            Schema::table('payment_intents', function (Blueprint $table) {
                $table->string('capture_mode', 10)->default('immediate')->after('channel');
                $table->decimal('captured_amount', 12, 2)->default(0)->after('refunded_amount');
                $table->timestamp('authorized_at')->nullable()->after('paid_at');
                $table->timestamp('captured_at')->nullable()->after('authorized_at');
                $table->timestamp('released_at')->nullable()->after('captured_at');
            });
        }

        if (Schema::hasTable('doctor_match_requests') && ! Schema::hasColumn('doctor_match_requests', 'payment_state')) {
            Schema::table('doctor_match_requests', function (Blueprint $table) {
                $table->string('payment_state', 20)->nullable();
                $table->unsignedBigInteger('payment_intent_id')->nullable()->index();
                $table->boolean('bill_medical_aid')->default(false);
            });
        }

        foreach (['clinics', 'doctor_profiles'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'payment_policy')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->string('payment_policy', 20)->nullable();
                    $table->decimal('deposit_percent', 5, 2)->nullable();
                });
            }
        }

        if (Schema::hasTable('rides') && ! Schema::hasColumn('rides', 'payment_intent_id')) {
            Schema::table('rides', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_intent_id')->nullable()->index();
            });
        }

        foreach (PaymentSettings::DEFAULTS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rides', 'payment_intent_id')) {
            Schema::table('rides', fn (Blueprint $t) => $t->dropColumn('payment_intent_id'));
        }
        foreach (['clinics', 'doctor_profiles'] as $t) {
            if (Schema::hasColumn($t, 'payment_policy')) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn(['payment_policy', 'deposit_percent']));
            }
        }
        if (Schema::hasColumn('doctor_match_requests', 'payment_state')) {
            Schema::table('doctor_match_requests', fn (Blueprint $t) => $t->dropColumn(['payment_state', 'payment_intent_id', 'bill_medical_aid']));
        }
        if (Schema::hasColumn('payment_intents', 'capture_mode')) {
            Schema::table('payment_intents', fn (Blueprint $t) => $t->dropColumn(['capture_mode', 'captured_amount', 'authorized_at', 'captured_at', 'released_at']));
        }
    }
};
