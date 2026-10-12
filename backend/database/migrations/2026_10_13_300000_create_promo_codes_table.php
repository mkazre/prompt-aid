<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo codes, managed at Staff → Marketplace → Promo codes. A code gives
 * a percent or fixed (ZAR) discount on the basket subtotal at pharmacy
 * checkout, within its limits (min spend, total uses, uses per user, dates).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promo_codes')) {
            return;
        }

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();
            $table->string('type', 10)->default('percent'); // percent | fixed
            $table->decimal('value', 10, 2)->default(0);
            $table->decimal('min_spend', 10, 2)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('applies_to', 20)->default('pharmacy'); // pharmacy | shuttle | both
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
