<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ride money ledger: trip fares, cancellation fees and no-show fees, each
 * charged through PaymentGatewayInterface. Rides had no payment record
 * before (invoices require a clinic), so this is their own ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending');
            $table->string('method', 30)->nullable();
            $table->string('gateway')->nullable();
            $table->string('gateway_ref')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['ride_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_charges');
    }
};
