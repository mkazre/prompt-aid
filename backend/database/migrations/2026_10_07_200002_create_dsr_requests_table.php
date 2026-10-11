<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** POPIA data subject requests (access / correction / deletion / objection). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsr_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['access', 'correction', 'deletion', 'objection']);
            $table->enum('status', ['received', 'in_progress', 'completed', 'rejected'])->default('received');
            $table->text('details')->nullable();
            $table->enum('channel', ['web', 'app', 'admin'])->default('web');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('retention_summary')->nullable();
            $table->string('export_path')->nullable();
            $table->unsignedBigInteger('export_size')->nullable();
            $table->timestamp('export_expires_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->date('retain_clinical_until')->nullable();
            $table->timestamp('fully_anonymised_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsr_requests');
    }
};
