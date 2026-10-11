<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned legal documents (terms, privacy policy, POPIA health-data
 * notice). The text itself lives in the CMS page named by page_slug; this
 * table only tracks which version is current and whether publishing it
 * should re-prompt existing users.
 *
 * Seeds v1 of each with requires_reconsent = 0 so deploying this does NOT
 * trigger a re-consent storm for existing users — they are only prompted
 * once an admin publishes a NEW version with "require re-consent" on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 50);
            $table->string('version', 20);
            $table->string('title');
            $table->string('page_slug')->nullable();
            $table->text('summary_of_changes')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('requires_reconsent')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['purpose', 'version']);
            $table->index(['purpose', 'published_at', 'effective_at']);
        });

        $now = now();
        foreach ([
            ['terms', 'Terms of use', 'terms'],
            ['privacy', 'Privacy policy', 'privacy'],
            ['health_data_processing', 'POPIA notice: processing of health information', 'legal-popia'],
        ] as [$purpose, $title, $slug]) {
            DB::table('legal_documents')->insert([
                'purpose' => $purpose,
                'version' => '1',
                'title' => $title,
                'page_slug' => $slug,
                'summary_of_changes' => 'Initial version.',
                'effective_at' => $now,
                'published_at' => $now,
                'requires_reconsent' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
