<?php

use App\Support\Widgets\WidgetCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('role', 32)->index();
            $table->string('screen', 32)->index();
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        // Seed the full catalog so every role's home (and the other
        // screens) light up on deploy — same rows as app_widgets_migration.sql.
        $now = now();
        DB::table('app_widgets')->insert(array_map(fn (array $row) => [
            'key' => $row['key'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'role' => $row['role'],
            'screen' => $row['screen'],
            'is_enabled' => true,
            'sort_order' => $row['sort_order'],
            'settings' => json_encode($row['settings']),
            'image' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], WidgetCatalog::SEED));
    }

    public function down(): void
    {
        Schema::dropIfExists('app_widgets');
    }
};
