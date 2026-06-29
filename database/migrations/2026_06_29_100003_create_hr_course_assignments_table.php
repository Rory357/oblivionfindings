<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 4 of the Training Hub handover: assignment records back the Assignments
 * tab + the Assign wizard. An assignment is a *requirement* placed on a person
 * to complete a course by a due date; it is expanded server-side from an
 * audience selection (individuals / role / site / cohort) into one row each.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_course_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hr_course_id')->constrained('hr_courses')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('hr_course_sessions')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('hr_course_enrollments')->nullOnDelete();
            // manual | role_rule | hs_requirement
            $table->string('source')->default('manual');
            $table->string('source_ref')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->date('due_at')->nullable();
            // assigned | in_progress | completed | overdue | waived
            $table->string('status')->default('assigned');
            $table->decimal('score', 5, 2)->nullable();
            $table->text('waived_reason')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'hr_course_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'user_id', 'hr_course_id'], 'hr_course_assign_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_course_assignments');
    }
};
