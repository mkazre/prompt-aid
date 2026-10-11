<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** POPIA s22 security compromise (breach) register. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->nullable()->unique();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('data_affected')->nullable();
            $table->unsignedInteger('users_affected_count')->default(0);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['open', 'investigating', 'contained', 'resolved', 'closed'])->default('open');
            $table->timestamp('regulator_notified_at')->nullable();
            $table->timestamp('users_notified_at')->nullable();
            $table->text('remediation')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incidents');
    }
};
