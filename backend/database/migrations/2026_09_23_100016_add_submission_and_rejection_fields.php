<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_schemes', function (Blueprint $table) {
            $table->enum('submission_mode', ['electronic', 'portal', 'manual'])->default('manual')->after('claims_endpoint');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('medical_schemes', function (Blueprint $table) {
            $table->dropColumn('submission_mode');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
