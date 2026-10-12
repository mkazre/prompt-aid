<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Footer newsletter signup (POPIA: consent recorded with time + IP).
        if (! Schema::hasTable('newsletter_subscribers')) {
            Schema::create('newsletter_subscribers', function (Blueprint $table) {
                $table->id();
                $table->string('email')->unique();
                $table->string('name')->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('source', 50)->default('footer');
                $table->timestamp('consented_at')->nullable();
                $table->string('consent_text', 500)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('unsubscribed_at')->nullable();
                $table->timestamps();

                $table->index('unsubscribed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
