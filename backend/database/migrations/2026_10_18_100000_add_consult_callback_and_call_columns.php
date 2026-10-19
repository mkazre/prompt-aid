<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orange triage → "speak to a doctor now": video consult or a doctor call
 * back, broadcast to every available doctor (first accept wins).
 *
 *  - appointments.mode / visit_type become plain strings so the new
 *    `callback` mode / `phone` visit type fit (existing values unchanged).
 *  - call-back details + SLA timestamps and the "incoming call" (ring)
 *    timestamps on the appointment, so specific-doctor bookings and
 *    on-demand requests share one shape.
 *  - the triage submission that led to the request, so doctors see the
 *    presenting symptoms in the alert.
 *
 * Additive and idempotent (hasColumn guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('mode', 20)->default('in_person')->change();
            $table->string('visit_type', 20)->default('clinic')->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'triage_submission_id')) {
                $table->unsignedBigInteger('triage_submission_id')->nullable()->index();
            }
            if (! Schema::hasColumn('appointments', 'callback_phone')) {
                $table->string('callback_phone', 32)->nullable();
            }
            if (! Schema::hasColumn('appointments', 'callback_channel')) {
                $table->string('callback_channel', 12)->nullable();
            }
            if (! Schema::hasColumn('appointments', 'callback_note')) {
                $table->string('callback_note', 500)->nullable();
            }
            if (! Schema::hasColumn('appointments', 'called_at')) {
                $table->timestamp('called_at')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'call_attempts')) {
                $table->unsignedSmallInteger('call_attempts')->default(0);
            }
            if (! Schema::hasColumn('appointments', 'call_outcome')) {
                $table->string('call_outcome', 24)->nullable();
            }
            if (! Schema::hasColumn('appointments', 'call_outcome_at')) {
                $table->timestamp('call_outcome_at')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'call_started_at')) {
                $table->timestamp('call_started_at')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'ring_answered_at')) {
                $table->timestamp('ring_answered_at')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'ring_declined_at')) {
                $table->timestamp('ring_declined_at')->nullable();
            }
        });

        Schema::table('doctor_match_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('doctor_match_requests', 'triage_submission_id')) {
                $table->unsignedBigInteger('triage_submission_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('doctor_match_requests', function (Blueprint $table) {
            if (Schema::hasColumn('doctor_match_requests', 'triage_submission_id')) {
                $table->dropIndex(['triage_submission_id']);
                $table->dropColumn('triage_submission_id');
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'triage_submission_id')) {
                $table->dropIndex(['triage_submission_id']);
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            foreach (['triage_submission_id', 'callback_phone', 'callback_channel', 'callback_note', 'called_at', 'call_attempts', 'call_outcome', 'call_outcome_at', 'call_started_at', 'ring_answered_at', 'ring_declined_at'] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        // mode / visit_type stay strings: narrowing them back to enums could fail on new values.
    }
};
