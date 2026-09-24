<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_submissions', function (Blueprint $table) {
            $table->decimal('pickup_lat', 10, 7)->nullable()->after('facility_types');
            $table->decimal('pickup_lng', 10, 7)->nullable()->after('pickup_lat');
            $table->string('pickup_address')->nullable()->after('pickup_lng');
            $table->string('emergency_contact_name')->nullable()->after('pickup_address');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->timestamp('contact_alerted_at')->nullable()->after('emergency_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('triage_submissions', function (Blueprint $table) {
            $table->dropColumn(['pickup_lat', 'pickup_lng', 'pickup_address', 'emergency_contact_name', 'emergency_contact_phone', 'contact_alerted_at']);
        });
    }
};
