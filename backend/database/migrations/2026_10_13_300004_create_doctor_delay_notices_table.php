<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Log of "Running late" alerts doctors sent to the day's remaining patients. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('doctor_delay_notices')) {
            return;
        }

        Schema::create('doctor_delay_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->text('message')->nullable();
            $table->date('for_date');
            $table->unsignedInteger('patients_notified')->default(0);
            $table->json('appointment_ids')->nullable();
            $table->string('source', 20)->default('staff'); // staff | app
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_delay_notices');
    }
};
