<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link onboarding task evidence to a real HrDocument artifact (replacing the
 * bare evidence_path string for new uploads).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_onboarding_tasks')) {
            return;
        }

        Schema::table('hr_onboarding_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_onboarding_tasks', 'hr_document_id')) {
                $table->foreignId('hr_document_id')
                    ->nullable()
                    ->after('evidence_path')
                    ->constrained('hr_documents')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_onboarding_tasks') || ! Schema::hasColumn('hr_onboarding_tasks', 'hr_document_id')) {
            return;
        }

        Schema::table('hr_onboarding_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hr_document_id');
        });
    }
};
