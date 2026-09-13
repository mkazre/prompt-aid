<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('third_party_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('company_name');
            $table->enum('service_type', ['lab', 'imaging', 'pharmacy_delivery', 'home_nursing'])->default('lab');
            $table->string('license_no')->nullable();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->enum('status', ['pending_approval', 'active', 'suspended'])->default('pending_approval');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_profiles');
    }
};
