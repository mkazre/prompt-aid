<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Help & support tickets — any signed-in role opens one from
        // /account/support or the app; staff answer at /staff → Support.
        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 20)->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('subject');
                $table->string('category', 30)->default('other');
                $table->string('status', 30)->default('open');
                $table->string('priority', 20)->default('normal');
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_reply_at')->nullable();
                $table->string('last_reply_by', 10)->nullable(); // user | staff
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'last_reply_at']);
                $table->index(['user_id', 'status']);
            });
        }

        // The thread. The ticket's opening message is its first reply row.
        if (! Schema::hasTable('support_ticket_replies')) {
            Schema::create('support_ticket_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('is_staff')->default(false);
                $table->text('body');
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
                $table->string('attachment_mime', 100)->nullable();
                $table->timestamps();

                $table->index(['support_ticket_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
        Schema::dropIfExists('support_tickets');
    }
};
