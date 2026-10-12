<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coloured announcement bar on the website + banner in the app.
        if (! Schema::hasTable('announcements')) {
            Schema::create('announcements', function (Blueprint $table) {
                $table->id();
                $table->string('title')->nullable();
                $table->text('message');
                $table->string('link_label')->nullable();
                $table->string('link_url', 500)->nullable();
                $table->string('color', 20)->default('#1F4E7A');
                $table->string('text_color', 20)->default('#FFFFFF');
                $table->string('audience', 20)->default('everyone');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_dismissible')->default(true);
                $table->boolean('is_active')->default(true);
                $table->integer('sort')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'starts_at', 'ends_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
