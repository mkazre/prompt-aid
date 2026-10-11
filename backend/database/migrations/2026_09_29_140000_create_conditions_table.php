<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed "Conditions & Symptoms" used by the search-as-you-type
 * endpoint. Each condition maps to a doctor specialization so tapping a
 * result routes the patient to doctors of the right speciality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conditions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('synonyms')->nullable(); // comma-separated alternative terms
            $table->string('specialization')->nullable(); // matched (LIKE) against doctor_profiles.specialization
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conditions');
    }
};
