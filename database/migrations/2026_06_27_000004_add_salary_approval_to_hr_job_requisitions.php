<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port the better HrJobPosting fields onto the canonical HrJobRequisition
 * (D7 / handover item 5): salary range, show-on-ad flag, screening questions
 * and an approval gate. Additive. The status column stays a string — the new
 * 'pending_approval' value needs no DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_requisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_job_requisitions', 'salary_range_min')) {
                $table->decimal('salary_range_min', 10, 2)->nullable()->after('employment_type');
            }
            if (! Schema::hasColumn('hr_job_requisitions', 'salary_range_max')) {
                $table->decimal('salary_range_max', 10, 2)->nullable()->after('salary_range_min');
            }
            if (! Schema::hasColumn('hr_job_requisitions', 'show_salary')) {
                $table->boolean('show_salary')->default(false)->after('salary_range_max');
            }
            if (! Schema::hasColumn('hr_job_requisitions', 'screening_questions')) {
                $table->json('screening_questions')->nullable()->after('show_salary');
            }
            if (! Schema::hasColumn('hr_job_requisitions', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false)->after('screening_questions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_requisitions', function (Blueprint $table) {
            foreach (['salary_range_min', 'salary_range_max', 'show_salary', 'screening_questions', 'requires_approval'] as $column) {
                if (Schema::hasColumn('hr_job_requisitions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
