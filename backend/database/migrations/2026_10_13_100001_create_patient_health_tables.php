<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient self-care features: favourite doctors, family members
 * (dependants), medications + per-dose log, and vitals. Add-only and
 * guarded, so it is safe to run on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('favourite_doctors')) {
            Schema::create('favourite_doctors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
                $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['patient_profile_id', 'doctor_profile_id']);
            });
        }

        if (! Schema::hasTable('family_members')) {
            Schema::create('family_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('relationship', 40);
                $table->date('dob')->nullable();
                $table->string('gender', 20)->nullable();
                $table->string('id_number', 40)->nullable();
                $table->string('medical_aid_scheme')->nullable();
                $table->string('medical_aid_number', 60)->nullable();
                $table->text('allergies')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('medications')) {
            Schema::create('medications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('dose', 120);
                // ["08:00","20:00"] — local (Africa/Johannesburg) times.
                $table->json('times');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('reminders_enabled')->default(true);
                $table->timestamps();
                $table->index(['start_date', 'end_date']);
            });
        }

        // One row per medication + day + time: the reminder sent-log AND the
        // taken log. The unique key makes each dose reminder fire once a day.
        if (! Schema::hasTable('medication_doses')) {
            Schema::create('medication_doses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('medication_id')->constrained()->cascadeOnDelete();
                $table->date('dose_date');
                $table->string('dose_time', 5);
                $table->timestamp('reminder_sent_at')->nullable();
                $table->timestamp('taken_at')->nullable();
                $table->timestamps();
                $table->unique(['medication_id', 'dose_date', 'dose_time']);
            });
        }

        if (! Schema::hasTable('vital_readings')) {
            Schema::create('vital_readings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('recorded_at');
                $table->unsignedSmallInteger('systolic')->nullable();
                $table->unsignedSmallInteger('diastolic')->nullable();
                $table->unsignedSmallInteger('pulse')->nullable();
                $table->decimal('glucose', 5, 1)->nullable();
                $table->decimal('weight', 5, 1)->nullable();
                $table->decimal('temperature', 4, 1)->nullable();
                $table->unsignedTinyInteger('spo2')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();
                $table->index(['patient_profile_id', 'recorded_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vital_readings');
        Schema::dropIfExists('medication_doses');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('favourite_doctors');
    }
};
