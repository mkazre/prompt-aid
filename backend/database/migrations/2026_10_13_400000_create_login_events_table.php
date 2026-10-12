<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login history: every sign-in (website session, Staff/Vendor/Partner
 * panels, app token) and every failed attempt for a known email.
 * See App\Support\LoginRecorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_events')) {
            return;
        }

        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('channel', 16); // web | staff | app
            $table->boolean('success')->default(true);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('panel', 32)->nullable(); // staff | vendor | partner (staff channel)
            $table->unsignedBigInteger('token_id')->nullable(); // Sanctum token created by an app login
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['success', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
