<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_certifications', function (Blueprint $table): void {
            $table->string('document_disk', 50)->nullable()->after('document_path');
            $table->char('evidence_sha256', 64)->nullable()->after('document_disk');
            $table->foreignId('supersedes_certification_id')
                ->nullable()
                ->after('evidence_sha256')
                ->constrained('site_certifications')
                ->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('supersedes_certification_id');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable()->after('revoked_by');

            $table->index(
                ['site_id', 'certification_type', 'revoked_at', 'deleted_at'],
                'site_certifications_assurance_head_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('site_certifications', function (Blueprint $table): void {
            $table->dropIndex('site_certifications_assurance_head_idx');
            $table->dropForeign(['supersedes_certification_id']);
            $table->dropForeign(['revoked_by']);
            $table->dropColumn([
                'document_disk',
                'evidence_sha256',
                'supersedes_certification_id',
                'revoked_at',
                'revoked_by',
                'revocation_reason',
            ]);
        });
    }
};
