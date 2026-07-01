<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source-of-truth unification: make HrCourse canonical and hang the legacy
 * compliance-facing StaffTrainingRecord off it via a nullable hr_course_id.
 *
 * - Adds `hr_course_id` (nullable FK → hr_courses) so a completion can be traced
 *   to its catalog course even when there is no legacy TrainingCourse.
 * - Makes `training_course_id` nullable (it was NOT NULL + cascade) so catalog-only
 *   completions can be recorded without a legacy course row.
 * - Backfills `hr_course_id` for existing records using the already-existing
 *   requirement back-link: hr_courses.compliance_requirement_id → requirement,
 *   requirement.reference_id → training_course_id.
 *
 * Fully additive + reversible; compliance readers keep a training_course_id
 * fallback so no shift-eligibility check regresses.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. training_course_id: drop the cascade FK, make nullable, re-add as nullOnDelete.
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->dropForeign(['training_course_id']);
        });
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->unsignedBigInteger('training_course_id')->nullable()->change();
        });
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->foreign('training_course_id')->references('id')->on('training_courses')->nullOnDelete();
        });

        // 2. Add hr_course_id (canonical link) + index.
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->foreignId('hr_course_id')->nullable()->after('training_course_id')->constrained('hr_courses')->nullOnDelete();
            $table->index(['user_id', 'hr_course_id']);
        });

        // 3. Backfill hr_course_id via the requirement back-link (portable, model-free).
        $links = DB::table('hr_courses')
            ->join('hr_compliance_requirements', 'hr_compliance_requirements.id', '=', 'hr_courses.compliance_requirement_id')
            ->where('hr_compliance_requirements.check_type', 'training_course')
            ->whereNotNull('hr_compliance_requirements.reference_id')
            ->select('hr_courses.id as hr_course_id', 'hr_compliance_requirements.reference_id as training_course_id')
            ->get();

        foreach ($links as $link) {
            DB::table('staff_training_records')
                ->where('training_course_id', $link->training_course_id)
                ->whereNull('hr_course_id')
                ->update(['hr_course_id' => $link->hr_course_id]);
        }
    }

    public function down(): void
    {
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->dropForeign(['hr_course_id']);
            $table->dropIndex(['user_id', 'hr_course_id']);
            $table->dropColumn('hr_course_id');
        });

        // Restore training_course_id to its NOT NULL + cascade shape.
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->dropForeign(['training_course_id']);
        });
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->unsignedBigInteger('training_course_id')->nullable(false)->change();
        });
        Schema::table('staff_training_records', function (Blueprint $table) {
            $table->foreign('training_course_id')->references('id')->on('training_courses')->cascadeOnDelete();
        });
    }
};
