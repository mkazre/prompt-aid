<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('kind', ['archive', 'single']);
            $table->string('entity_type');
            $table->json('conditions')->nullable();
            $table->integer('priority')->default(0);
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['kind', 'entity_type', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_templates');
    }
};
