<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance: commission rules, the provider/platform ledger, payouts and
 * provider bank details. Money columns are DECIMAL(12,2); services work in
 * integer cents (App\Support\Money) and only convert at the edges.
 *
 * Seeds the approved global defaults (consult 17.5%, in-person 12.5%,
 * shuttle 22.5%, pharmacy 7.5%, lab/partner 12.5%, patient fee 0) and turns
 * any pharmacy whose legacy `commission_rate` differs from the old column
 * default (10) into a pharmacy-scoped override, so negotiated deals survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_rules')) {
            Schema::create('commission_rules', function (Blueprint $table) {
                $table->id();
                $table->string('service_type', 30);           // consult|appointment|shuttle|pharmacy|lab
                $table->string('scope', 20)->default('global'); // global|clinic|doctor|pharmacy|partner|driver
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->decimal('percent', 6, 3)->nullable();  // null = inherit from the less specific rule
                $table->decimal('fixed_amount', 10, 2)->nullable();
                $table->decimal('patient_fee', 10, 2)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamp('effective_from')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['service_type', 'scope', 'scope_id'], 'commission_rules_lookup');
            });
        }

        if (! Schema::hasTable('ledger_entries')) {
            Schema::create('ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->string('account_type', 20);             // platform|doctor|clinic|driver|pharmacy|partner
                $table->unsignedBigInteger('account_id')->default(0); // 0 for platform
                $table->string('source_type', 40)->nullable();  // payment_intent|ride_charge|order|payment|ride|appointment|lab_request|payout|manual
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('service_type', 30)->nullable();
                $table->string('entry_type', 30);               // earning|commission|fee|refund_reversal|adjustment|payout|cash_collected
                $table->string('component', 20)->nullable();    // for reversals: earning|commission|fee|cash
                $table->string('group_key', 100)->nullable();   // links a capture's rows and their reversals
                $table->decimal('amount', 12, 2);               // signed: + owed to the account, - owed by it
                $table->char('currency', 3)->default('ZAR');
                $table->decimal('balance_after', 12, 2)->default(0);
                $table->unsignedBigInteger('payout_id')->nullable();
                $table->json('meta')->nullable();
                $table->string('description', 255)->nullable();
                $table->string('idempotency_key', 191)->unique();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['account_type', 'account_id', 'created_at'], 'ledger_account_time');
                $table->index(['source_type', 'source_id'], 'ledger_source');
                $table->index('group_key');
                $table->index(['entry_type', 'created_at']);
            });
        }

        if (! Schema::hasTable('ledger_balances')) {
            Schema::create('ledger_balances', function (Blueprint $table) {
                $table->id();
                $table->string('account_type', 20);
                $table->unsignedBigInteger('account_id')->default(0);
                $table->decimal('balance', 12, 2)->default(0);
                $table->timestamps();
                $table->unique(['account_type', 'account_id']);
            });
        }

        if (! Schema::hasTable('payout_accounts')) {
            Schema::create('payout_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('account_type', 20);
                $table->unsignedBigInteger('account_id');
                $table->string('account_holder', 150);
                $table->string('bank_name', 100);
                $table->string('branch_code', 20);
                $table->text('account_number');                 // Crypt-encrypted
                $table->string('account_last4', 4)->nullable();
                $table->string('bank_account_type', 20)->default('cheque'); // cheque|savings|transmission
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamps();
                $table->unique(['account_type', 'account_id']);
            });
        }

        if (! Schema::hasTable('payout_batches')) {
            Schema::create('payout_batches', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 40)->unique();
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('status', 20)->default('pending'); // pending|approved|completed|cancelled
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->unsignedInteger('payout_count')->default(0);
                $table->string('source', 20)->default('manual');   // manual|auto
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('exported_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payouts')) {
            Schema::create('payouts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payout_batch_id')->nullable()->constrained('payout_batches')->nullOnDelete();
                $table->string('account_type', 20);
                $table->unsignedBigInteger('account_id');
                $table->unsignedBigInteger('payout_account_id')->nullable();
                $table->decimal('amount', 12, 2);
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('status', 20)->default('pending'); // pending|approved|paid|failed|cancelled
                $table->string('reference', 40)->nullable();      // beneficiary statement reference
                $table->string('bank_reference', 100)->nullable(); // bank's confirmation / trace number
                $table->timestamp('paid_at')->nullable();
                $table->string('failed_reason', 255)->nullable();
                $table->unsignedBigInteger('actioned_by')->nullable();
                $table->timestamps();
                $table->index(['account_type', 'account_id', 'status']);
            });
        }

        if (Schema::hasTable('clinics') && ! Schema::hasColumn('clinics', 'payout_to')) {
            Schema::table('clinics', function (Blueprint $table) {
                $table->string('payout_to', 10)->default('doctor'); // doctor|clinic
            });
        }

        $now = now();
        if (! DB::table('commission_rules')->where('scope', 'global')->exists()) {
            foreach (['consult' => 17.5, 'appointment' => 12.5, 'shuttle' => 22.5, 'pharmacy' => 7.5, 'lab' => 12.5] as $svc => $pct) {
                DB::table('commission_rules')->insert([
                    'service_type' => $svc, 'scope' => 'global', 'scope_id' => null, 'percent' => $pct,
                    'fixed_amount' => 0, 'patient_fee' => 0, 'active' => true, 'effective_from' => null,
                    'note' => 'Approved default', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        if (Schema::hasTable('pharmacies') && Schema::hasColumn('pharmacies', 'commission_rate')) {
            foreach (DB::table('pharmacies')->whereNotNull('commission_rate')->where('commission_rate', '!=', 10)->get(['id', 'commission_rate']) as $p) {
                $exists = DB::table('commission_rules')->where(['service_type' => 'pharmacy', 'scope' => 'pharmacy', 'scope_id' => $p->id])->exists();
                if (! $exists) {
                    DB::table('commission_rules')->insert([
                        'service_type' => 'pharmacy', 'scope' => 'pharmacy', 'scope_id' => $p->id, 'percent' => $p->commission_rate,
                        'fixed_amount' => null, 'patient_fee' => null, 'active' => true, 'effective_from' => null,
                        'note' => 'Carried over from the pharmacy commission rate', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clinics', 'payout_to')) {
            Schema::table('clinics', fn (Blueprint $table) => $table->dropColumn('payout_to'));
        }
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_batches');
        Schema::dropIfExists('payout_accounts');
        Schema::dropIfExists('ledger_balances');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('commission_rules');
    }
};
