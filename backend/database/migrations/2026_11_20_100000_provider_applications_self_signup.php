<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self sign-up from the app: an application can now own the applicant's
 * login (created inactive with their own password, activated on approval),
 * plus where it came from and the extra details (category, specialty, address).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('provider_applications', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('provider_applications', 'source')) {
                $table->string('source', 20)->default('web')->after('status');
            }
            if (! Schema::hasColumn('provider_applications', 'meta')) {
                $table->json('meta')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('provider_applications', function (Blueprint $table) {
            if (Schema::hasColumn('provider_applications', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
            foreach (['source', 'meta'] as $c) {
                if (Schema::hasColumn('provider_applications', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
