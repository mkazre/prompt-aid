<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments: one `payment_intents` row per checkout attempt (invoice,
 * order, ride charge or subscription), and a raw `payment_events` log of
 * every webhook / ITN / notify call received (verified or not).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_intents')) {
            Schema::create('payment_intents', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->nullableMorphs('payable');
                $table->decimal('amount', 12, 2);
                $table->char('currency', 3)->default('ZAR');
                $table->string('gateway', 20);
                $table->string('channel', 10)->default('web');
                $table->string('status', 20)->default('pending')->index();
                $table->string('reference', 64)->unique();
                $table->string('gateway_ref', 120)->nullable()->index();
                $table->string('gateway_token', 191)->nullable();
                $table->string('description', 191)->nullable();
                $table->string('idempotency_key', 120)->nullable()->unique();
                $table->string('embed_token_hash', 64)->nullable();
                $table->timestamp('embed_expires_at')->nullable();
                $table->json('metadata')->nullable();
                $table->decimal('refunded_amount', 12, 2)->default(0);
                $table->string('failure_reason', 255)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();
                $table->string('gateway', 20)->index();
                $table->string('event_type', 60)->nullable();
                $table->string('reference', 120)->nullable()->index();
                $table->boolean('verified')->default(false);
                $table->string('verification_note', 255)->nullable();
                $table->string('outcome', 60)->nullable();
                $table->string('ip', 45)->nullable();
                $table->json('payload')->nullable();
                $table->json('headers')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_intents');
    }
};
