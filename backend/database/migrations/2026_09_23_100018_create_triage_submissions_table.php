<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->enum('level', ['red', 'orange', 'yellow', 'green']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('age_band')->nullable();
            $table->boolean('pregnant')->default(false);
            $table->json('symptoms')->nullable();
            $table->json('discriminators')->nullable();
            $table->json('observations')->nullable();
            $table->json('reasons')->nullable();
            $table->json('facility_types')->nullable();
            $table->string('ip')->nullable();
            $table->boolean('staff_notified')->default(false);
            $table->timestamps();

            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_submissions');
    }
};
