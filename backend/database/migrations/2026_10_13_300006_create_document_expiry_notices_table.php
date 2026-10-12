<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency markers for document expiry reminders: one row per
 * document, expiry date and threshold (30 / 7 / 0 days). Renewing a
 * document (new expiry date) re-arms its reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_expiry_notices')) {
            return;
        }

        Schema::create('document_expiry_notices', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 20); // driver | provider
            $table->unsignedBigInteger('document_id');
            $table->date('expires_on');
            $table->unsignedSmallInteger('threshold_days');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['document_type', 'document_id', 'expires_on', 'threshold_days'], 'doc_expiry_notice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expiry_notices');
    }
};
