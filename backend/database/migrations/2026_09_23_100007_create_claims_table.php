<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('claimable_type');
            $table->unsignedBigInteger('claimable_id');
            $table->foreignId('scheme_membership_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['draft', 'submitted', 'accepted', 'part_paid', 'rejected'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->string('scheme_ref')->nullable();
            $table->decimal('amount_claimed', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['claimable_type', 'claimable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
