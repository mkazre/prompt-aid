<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('claims_endpoint')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('scheme_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medical_scheme_id')->constrained()->cascadeOnDelete();
            $table->string('member_number');
            $table->string('dependant_code')->nullable();
            $table->string('main_member_name')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheme_memberships');
        Schema::dropIfExists('medical_schemes');
    }
};
