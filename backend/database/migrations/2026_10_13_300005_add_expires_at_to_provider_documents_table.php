<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Add-only: expiry date on provider (applicant) documents. Driver documents already have one. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('provider_documents', 'expires_at')) {
            Schema::table('provider_documents', function (Blueprint $table) {
                $table->date('expires_at')->nullable()->after('original_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('provider_documents', 'expires_at')) {
            Schema::table('provider_documents', function (Blueprint $table) {
                $table->dropColumn('expires_at');
            });
        }
    }
};
