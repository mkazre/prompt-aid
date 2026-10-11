<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('image')->nullable();
            $table->unsignedSmallInteger('read_minutes')->default(3);
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        // A few genuine, general-guidance starter articles so the widget
        // isn't empty on deploy — admins edit/unpublish them in Site →
        // Health Articles. Same rows as app_widgets_migration.sql.
        $now = now();
        foreach (\App\Models\HealthArticle::STARTER_ARTICLES as $i => $article) {
            \Illuminate\Support\Facades\DB::table('health_articles')->insert($article + [
                'image' => null,
                'published_at' => $now->copy()->subDays($i),
                'is_published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_articles');
    }
};
