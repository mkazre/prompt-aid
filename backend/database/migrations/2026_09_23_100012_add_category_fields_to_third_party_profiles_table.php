<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_party_profiles', function (Blueprint $table) {
            $table->enum('category', [
                'lab', 'imaging', 'physio', 'optometry', 'dental', 'dietetics', 'audiology', 'home_nursing', 'other',
            ])->default('lab')->after('service_type');
            $table->json('service_area')->nullable()->after('category');
            $table->boolean('accepts_walk_ins')->default(false)->after('service_area');
        });
    }

    public function down(): void
    {
        Schema::table('third_party_profiles', function (Blueprint $table) {
            $table->dropColumn(['category', 'service_area', 'accepts_walk_ins']);
        });
    }
};
