<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Service/Product carry multiple ServiceCategory tags, replacing
 * the single free-text `category` string and the unused singular
 * `service_category_id` FK (both left in place for now — see the
 * backfill migration that follows this one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_service_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'service_category_id']);
        });

        Schema::create('product_service_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'service_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_service_category');
        Schema::dropIfExists('service_service_category');
    }
};
