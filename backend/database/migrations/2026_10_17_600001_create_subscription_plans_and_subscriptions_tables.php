<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring billing (PayFast subscriptions): admin-defined plans and the
 * users subscribed to them. `kind = card_token` rows are PayFast
 * tokenisation agreements (subscription_type=2) used for ad-hoc charges
 * such as shuttle fares / no-show fees.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 160)->unique();
                $table->text('description')->nullable();
                $table->string('scope', 40)->index();
                $table->decimal('amount', 12, 2);
                $table->string('frequency', 20)->default('monthly');
                $table->unsignedInteger('cycles')->default(0);
                $table->decimal('discount_percent', 5, 2)->nullable();
                $table->json('benefits')->nullable();
                $table->boolean('is_active')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 20)->default('recurring');
                $table->string('gateway', 20);
                $table->string('token', 191)->nullable()->index();
                $table->string('status', 20)->default('pending')->index();
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('frequency', 20)->nullable();
                $table->unsignedInteger('cycles')->default(0);
                $table->unsignedInteger('cycles_completed')->default(0);
                $table->string('subject_type', 60)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('next_billing_at')->nullable();
                $table->timestamp('last_paid_at')->nullable();
                $table->timestamp('paused_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancel_reason', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'member_discount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('member_discount', 12, 2)->nullable()->after('discount_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'member_discount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('member_discount');
            });
        }
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
