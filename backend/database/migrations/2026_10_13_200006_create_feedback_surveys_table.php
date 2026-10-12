<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "How was your visit?" NPS surveys. One row per completed
        // appointment / ride; the row itself is the "prompt sent" marker.
        if (! Schema::hasTable('feedback_surveys')) {
            Schema::create('feedback_surveys', function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('subject_type', 20); // appointment | ride
                $table->unsignedBigInteger('subject_id');
                $table->string('subject_label')->nullable();
                $table->unsignedTinyInteger('score')->nullable(); // 0–10
                $table->text('comment')->nullable();
                $table->timestamp('prompted_at')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();

                $table->unique(['subject_type', 'subject_id']);
                $table->index(['user_id', 'responded_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_surveys');
    }
};
