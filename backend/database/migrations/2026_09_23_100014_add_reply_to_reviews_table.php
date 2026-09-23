<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('reply')->nullable()->after('comment');
            $table->timestamp('replied_at')->nullable()->after('reply');
        });

        Schema::table('lab_results', function (Blueprint $table) {
            $table->boolean('critical')->default(false)->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['reply', 'replied_at']);
        });

        Schema::table('lab_results', function (Blueprint $table) {
            $table->dropColumn('critical');
        });
    }
};
