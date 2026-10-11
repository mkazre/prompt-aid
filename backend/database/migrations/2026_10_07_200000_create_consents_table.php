<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POPIA consent ledger. Append-only by design: granting creates a row,
 * withdrawing stamps withdrawn_at on the active row, re-granting creates a
 * new row — so the full history of what a user agreed to (and which
 * version) is always reconstructable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 50);
            $table->string('version', 20)->nullable();
            $table->unsignedBigInteger('legal_document_id')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->enum('channel', ['web', 'app', 'admin'])->default('web');
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'withdrawn_at']);
            $table->index('purpose');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
