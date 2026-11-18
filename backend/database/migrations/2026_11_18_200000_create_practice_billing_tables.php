<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Practice (B2B) billing: practices — clinics, or independent doctors without
 * a clinic — pay PromptAid a monthly platform subscription. Kept SEPARATE from
 * the patient `subscription_plans` / `subscriptions` tables (different
 * lifecycle: trial, seats, past_due → grace → suspended, request gating).
 * All money is integer cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('practice_plans')) {
            Schema::create('practice_plans', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150);
                $t->string('slug', 191)->unique();
                $t->text('description')->nullable();
                $t->string('audience', 20)->default('any');           // any | clinic | doctor (independent)
                $t->unsignedInteger('price_cents');                    // per month
                $t->unsignedInteger('annual_price_cents')->nullable(); // per year (optional)
                $t->string('billing_basis', 20)->default('flat');      // flat | per_seat
                $t->unsignedSmallInteger('included_seats')->default(1);
                $t->unsignedInteger('extra_seat_cents')->default(0);   // per extra doctor per month
                $t->unsignedSmallInteger('max_doctors')->nullable();   // null = unlimited
                $t->boolean('receives_instant_requests')->default(true);
                $t->boolean('listed_in_search')->default(true);
                $t->boolean('accepts_bookings')->default(true);
                $t->unsignedSmallInteger('trial_days')->default(0);
                $t->json('features')->nullable();                      // marketing bullet points
                $t->boolean('is_active')->default(true);
                $t->integer('sort_order')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('practice_subscriptions')) {
            Schema::create('practice_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->string('subscriber_type', 20);                     // clinic | doctor
                $t->unsignedBigInteger('subscriber_id');
                $t->foreignId('practice_plan_id')->nullable()->constrained('practice_plans')->nullOnDelete();
                $t->string('status', 20)->default('trialing');         // trialing|active|past_due|grace|suspended|cancelled|incomplete
                $t->string('billing_cycle', 10)->default('monthly');   // monthly | annual
                $t->unsignedSmallInteger('seats')->default(1);
                $t->unsignedInteger('amount_cents')->default(0);       // recurring amount per cycle
                $t->string('gateway', 20)->nullable();
                $t->string('token', 120)->nullable();                  // PayFast subscription token
                $t->unsignedBigInteger('first_intent_id')->nullable(); // checkout whose m_payment_id renewals reuse
                $t->unsignedBigInteger('pending_intent_id')->nullable();
                $t->json('pending_checkout')->nullable();              // plan/cycle/seats/amount being paid for
                $t->timestamp('trial_ends_at')->nullable();
                $t->timestamp('current_period_start')->nullable();
                $t->timestamp('current_period_end')->nullable();
                $t->timestamp('next_billing_at')->nullable();
                $t->timestamp('last_paid_at')->nullable();
                $t->timestamp('past_due_at')->nullable();
                $t->timestamp('grace_ends_at')->nullable();
                $t->timestamp('suspended_at')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->boolean('cancel_at_period_end')->default(false);
                $t->string('cancel_reason', 255)->nullable();
                $t->timestamp('comped_until')->nullable();             // admin waiver: free until this date
                $t->string('comp_reason', 255)->nullable();
                $t->timestamp('last_reminder_at')->nullable();
                $t->unsignedSmallInteger('reminders_sent')->default(0);
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->unique(['subscriber_type', 'subscriber_id'], 'practice_subs_subscriber_unique');
                $t->index(['status', 'next_billing_at'], 'practice_subs_status_next');
            });
        }

        if (! Schema::hasTable('practice_invoices')) {
            Schema::create('practice_invoices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('practice_subscription_id')->constrained('practice_subscriptions')->cascadeOnDelete();
                $t->string('number', 40)->unique();
                $t->string('plan_name', 150)->nullable();
                $t->string('description', 255)->nullable();
                $t->timestamp('period_start')->nullable();
                $t->timestamp('period_end')->nullable();
                $t->unsignedSmallInteger('seats')->default(1);
                $t->unsignedInteger('amount_cents');
                $t->string('status', 20)->default('paid');             // paid | failed | waived | void
                $t->string('method', 20)->nullable();                  // payfast | mock | eft | comp | manual
                $t->unsignedBigInteger('payment_intent_id')->nullable();
                $t->string('gateway_ref', 120)->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $t->json('meta')->nullable();
                $t->timestamps();
                $t->index(['practice_subscription_id', 'created_at'], 'practice_invoices_sub_created');
                $t->index('gateway_ref');
            });
        }

        if (! Schema::hasTable('practice_billing_events')) {
            Schema::create('practice_billing_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('practice_subscription_id')->constrained('practice_subscriptions')->cascadeOnDelete();
                $t->string('type', 40);
                $t->string('message', 255);
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->json('data')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['practice_subscription_id', 'id'], 'practice_billing_events_sub');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_billing_events');
        Schema::dropIfExists('practice_invoices');
        Schema::dropIfExists('practice_subscriptions');
        Schema::dropIfExists('practice_plans');
    }
};
