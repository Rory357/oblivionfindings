<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $coverageCollision = DB::table('site_coverage_requirements')
            ->select(['site_id', 'name', 'day_of_week', 'starts_time', 'ends_time'])
            ->groupBy('site_id', 'name', 'day_of_week', 'starts_time', 'ends_time')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($coverageCollision !== null) {
            throw new RuntimeException(
                'Cannot enforce canonical Site coverage identity while duplicate Site, name, day, and time rows exist.',
            );
        }

        Schema::table('site_certifications', function (Blueprint $table): void {
            $table->dropIndex('site_certifications_organization_id_index');
            $table->dropIndex('site_certifications_status_expiry_date_index');
            $table->index(['site_id', 'status', 'expiry_date'], 'site_certifications_site_status_expiry_idx');
            $table->index(['site_id', 'next_review_date'], 'site_certifications_site_review_idx');
        });

        Schema::table('site_compliance_checks', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('risk_rating');
            $table->dropIndex('site_compliance_checks_organization_id_index');
            $table->dropIndex('site_compliance_checks_status_scheduled_date_index');
            $table->index(['site_id', 'status', 'scheduled_date'], 'site_compliance_checks_site_status_schedule_idx');
            $table->index(['site_id', 'follow_up_date'], 'site_compliance_checks_site_follow_up_idx');
        });

        Schema::table('site_staff_requirements', function (Blueprint $table): void {
            $table->dropIndex('site_staff_requirements_organization_id_index');
            $table->index(
                ['site_id', 'is_active', 'category'],
                'site_staff_requirements_site_active_category_idx',
            );
        });

        Schema::table('site_coverage_requirements', function (Blueprint $table): void {
            $table->dropIndex('site_coverage_requirements_organization_id_index');
            $table->unique(
                ['site_id', 'name', 'day_of_week', 'starts_time', 'ends_time'],
                'site_coverage_requirements_identity_uq',
            );
        });

        Schema::table('site_feedback', function (Blueprint $table): void {
            $table->dropIndex('site_feedback_organization_id_index');
            $table->dropIndex('site_feedback_status_index');
            $table->index(['site_id', 'status', 'created_at'], 'site_feedback_site_status_created_idx');
            $table->index(['site_id', 'created_at'], 'site_feedback_site_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('site_feedback', function (Blueprint $table): void {
            $table->index('organization_id', 'site_feedback_organization_id_index');
            $table->index('status', 'site_feedback_status_index');
            $table->dropIndex('site_feedback_site_status_created_idx');
            $table->dropIndex('site_feedback_site_created_idx');
        });

        Schema::table('site_coverage_requirements', function (Blueprint $table): void {
            $table->index('organization_id', 'site_coverage_requirements_organization_id_index');
            $table->dropUnique('site_coverage_requirements_identity_uq');
        });

        Schema::table('site_staff_requirements', function (Blueprint $table): void {
            $table->index('organization_id', 'site_staff_requirements_organization_id_index');
            $table->dropIndex('site_staff_requirements_site_active_category_idx');
        });

        Schema::table('site_compliance_checks', function (Blueprint $table): void {
            $table->index('organization_id', 'site_compliance_checks_organization_id_index');
            $table->index(
                ['status', 'scheduled_date'],
                'site_compliance_checks_status_scheduled_date_index',
            );
            $table->dropIndex('site_compliance_checks_site_status_schedule_idx');
            $table->dropIndex('site_compliance_checks_site_follow_up_idx');
            $table->dropColumn('notes');
        });

        Schema::table('site_certifications', function (Blueprint $table): void {
            $table->index('organization_id', 'site_certifications_organization_id_index');
            $table->index(
                ['status', 'expiry_date'],
                'site_certifications_status_expiry_date_index',
            );
            $table->dropIndex('site_certifications_site_status_expiry_idx');
            $table->dropIndex('site_certifications_site_review_idx');
        });
    }
};
