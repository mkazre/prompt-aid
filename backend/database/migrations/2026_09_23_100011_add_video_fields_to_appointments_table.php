<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('mode', ['in_person', 'video'])->default('in_person')->after('visit_type');
            $table->string('meet_url')->nullable()->after('mode');
            $table->string('meet_event_id')->nullable()->after('meet_url');
            $table->timestamp('meet_created_at')->nullable()->after('meet_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['mode', 'meet_url', 'meet_event_id', 'meet_created_at']);
        });
    }
};
