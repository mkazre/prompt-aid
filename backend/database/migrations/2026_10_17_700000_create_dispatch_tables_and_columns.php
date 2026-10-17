<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified dispatch (broadcast offers to every qualifying provider, first
 * accept wins), provider availability NOW / LATER / TOMORROW, patient
 * search radius, trip-PIN rate limiting and rider-profile fields.
 * Additive only — every column is nullable or has a default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provider_availability')) {
            Schema::create('provider_availability', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('role', 20)->index();
                $table->boolean('now_on')->default(false);
                $table->timestamp('last_seen_at')->nullable();
                $table->date('later_date')->nullable();
                $table->time('later_from')->nullable();
                $table->time('later_to')->nullable();
                $table->date('tomorrow_date')->nullable();
                $table->time('tomorrow_from')->nullable();
                $table->time('tomorrow_to')->nullable();
                $table->json('weekly')->nullable();
                $table->decimal('max_radius_km', 6, 1)->nullable();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->timestamp('location_at')->nullable();
                $table->timestamps();
                $table->index(['role', 'now_on', 'last_seen_at']);
            });
        }

        if (! Schema::hasTable('dispatch_jobs')) {
            Schema::create('dispatch_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('service', 30);
                $table->string('subject_type', 80);
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('patient_user_id')->nullable();
                $table->string('mode', 12)->default('broadcast');
                $table->string('status', 16)->default('searching');
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->decimal('radius_km', 6, 1)->nullable();
                $table->string('capability', 120)->nullable();
                $table->dateTime('window_from')->nullable();
                $table->dateTime('window_to')->nullable();
                $table->unsignedBigInteger('target_user_id')->nullable();
                $table->unsignedBigInteger('accepted_by_user_id')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedInteger('offers_count')->default(0);
                $table->string('closed_reason', 60)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['service', 'status']);
                $table->index(['subject_type', 'subject_id']);
                $table->index(['status', 'expires_at']);
            });
        }

        if (! Schema::hasTable('dispatch_offers')) {
            Schema::create('dispatch_offers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dispatch_job_id')->constrained('dispatch_jobs')->cascadeOnDelete();
                $table->unsignedBigInteger('provider_user_id');
                $table->string('status', 12)->default('pending');
                $table->decimal('distance_km', 8, 2)->nullable();
                $table->timestamp('offered_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->string('decline_reason', 255)->nullable();
                $table->timestamps();
                $table->index(['provider_user_id', 'status']);
                $table->index(['dispatch_job_id', 'status']);
            });
        }

        Schema::table('rides', function (Blueprint $table) {
            if (! Schema::hasColumn('rides', 'radius_km')) {
                $table->decimal('radius_km', 6, 1)->nullable();
            }
            if (! Schema::hasColumn('rides', 'dispatch_mode')) {
                $table->string('dispatch_mode', 12)->nullable();
            }
            if (! Schema::hasColumn('rides', 'pickup_notes')) {
                $table->string('pickup_notes', 500)->nullable();
            }
            if (! Schema::hasColumn('rides', 'booked_by_user_id')) {
                $table->unsignedBigInteger('booked_by_user_id')->nullable();
            }
            if (! Schema::hasColumn('rides', 'pin_attempts')) {
                $table->unsignedSmallInteger('pin_attempts')->default(0);
            }
            if (! Schema::hasColumn('rides', 'pin_locked_until')) {
                $table->timestamp('pin_locked_until')->nullable();
            }
            if (! Schema::hasColumn('rides', 'closed_reason')) {
                $table->string('closed_reason', 60)->nullable();
            }
        });

        Schema::table('ride_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('ride_offers', 'mode')) {
                $table->string('mode', 12)->nullable();
            }
        });

        if (Schema::hasTable('doctor_match_requests')) {
            Schema::table('doctor_match_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('doctor_match_requests', 'radius_km')) {
                    $table->decimal('radius_km', 6, 1)->nullable();
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'id_verified_at')) {
                $table->timestamp('id_verified_at')->nullable();
            }
        });

        Schema::table('patient_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('patient_profiles', 'mobility_needs')) {
                $table->string('mobility_needs', 255)->nullable();
            }
            if (! Schema::hasColumn('patient_profiles', 'search_radius_km')) {
                $table->decimal('search_radius_km', 6, 1)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_offers');
        Schema::dropIfExists('dispatch_jobs');
        Schema::dropIfExists('provider_availability');

        $drop = [
            'rides' => ['radius_km', 'dispatch_mode', 'pickup_notes', 'booked_by_user_id', 'pin_attempts', 'pin_locked_until', 'closed_reason'],
            'ride_offers' => ['mode'],
            'doctor_match_requests' => ['radius_km'],
            'users' => ['phone_verified_at', 'id_verified_at'],
            'patient_profiles' => ['mobility_needs', 'search_radius_km'],
        ];
        foreach ($drop as $tableName => $cols) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $existing = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($tableName, $c)));
            if ($existing) {
                Schema::table($tableName, fn (Blueprint $t) => $t->dropColumn($existing));
            }
        }
    }
};
