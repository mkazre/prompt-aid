<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_ref')->unique();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('priority', ['routine', 'urgent'])->default('routine');
            $table->text('clinical_notes')->nullable();
            $table->string('collection_address')->nullable();
            $table->decimal('collection_lat', 10, 7)->nullable();
            $table->decimal('collection_lng', 10, 7)->nullable();
            $table->enum('status', [
                'requested', 'accepted', 'sample_collected', 'processing', 'completed', 'cancelled',
            ])->default('requested');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('sample_collected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_requests');
    }
};
