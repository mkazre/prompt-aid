<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('page_blocks')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('type');
            $table->json('props')->nullable();
            $table->json('styles')->nullable();
            $table->json('visibility')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'parent_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_blocks');
    }
};
