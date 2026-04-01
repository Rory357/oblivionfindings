<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('title');
            $table->text('summary')->nullable()->after('description');
            $table->text('responsibilities')->nullable()->after('requirements');
            $table->boolean('is_remote')->default(false)->after('employment_type');
            $table->boolean('is_internal')->default(false)->after('is_remote');
            $table->json('notification_emails')->nullable()->after('applications_count');
            $table->foreignId('hiring_manager_id')->nullable()->after('notification_emails')->constrained('users')->nullOnDelete();
            $table->boolean('requires_approval')->default(false)->after('hiring_manager_id');
            $table->foreignId('approved_by')->nullable()->after('requires_approval')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->unsignedInteger('views_count')->default(0)->after('applications_count');
            $table->json('screening_questions')->nullable()->after('views_count');
            $table->timestamp('closing_soon_notified_at')->nullable()->after('closes_at');

            $table->unique(['tenant_id', 'slug']);
        });

        // Expand status to include pending_approval
        // Since SQLite doesn't support ALTER COLUMN for enum changes,
        // the status column is already a string so 'pending_approval' just works.

        Schema::table('hr_applications', function (Blueprint $table) {
            $table->foreignId('job_posting_id')->nullable()->after('requisition_id')->constrained('hr_job_postings')->nullOnDelete();
            $table->json('screening_answers')->nullable()->after('answers');
            $table->string('candidate_tracking_token', 64)->nullable()->unique()->after('screening_answers');
        });
    }

    public function down(): void
    {
        Schema::table('hr_applications', function (Blueprint $table) {
            $table->dropForeign(['job_posting_id']);
            $table->dropColumn(['job_posting_id', 'screening_answers', 'candidate_tracking_token']);
        });

        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropForeign(['hiring_manager_id']);
            $table->dropForeign(['approved_by']);
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropColumn([
                'slug', 'summary', 'responsibilities', 'is_remote', 'is_internal',
                'notification_emails', 'hiring_manager_id', 'requires_approval',
                'approved_by', 'approved_at', 'views_count', 'screening_questions',
                'closing_soon_notified_at',
            ]);
        });
    }
};
