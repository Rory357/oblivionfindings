<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, nullable columns backing the unified Performance & Development hub
 * lifecycle actions. No data is altered or dropped — every column is nullable
 * or defaulted, so existing rows are untouched and the change is reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_supervision_notes', function (Blueprint $table) {
            // Explicit lifecycle state (scheduled/awaiting-ack/acknowledged/…).
            // Null = derive from acknowledgement + next_session_date at read time.
            if (! Schema::hasColumn('hr_supervision_notes', 'status')) {
                $table->string('status')->nullable()->after('session_type');
            }
            // Recurrence cadence captured in the supervision wizard.
            if (! Schema::hasColumn('hr_supervision_notes', 'cadence')) {
                $table->string('cadence')->nullable()->after('next_session_date');
            }
        });

        Schema::table('hr_performance_improvement_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_performance_improvement_plans', 'employee_acknowledged')) {
                $table->boolean('employee_acknowledged')->default(false)->after('outcome_notes');
            }
            if (! Schema::hasColumn('hr_performance_improvement_plans', 'employee_acknowledged_at')) {
                $table->datetime('employee_acknowledged_at')->nullable()->after('employee_acknowledged');
            }
        });

        Schema::table('hr_pip_milestones', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_pip_milestones', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('status');
            }
            if (! Schema::hasColumn('hr_pip_milestones', 'evidence_path')) {
                $table->string('evidence_path')->nullable()->after('evidence');
            }
        });

        Schema::table('hr_competencies', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_competencies', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('sort_order');
            }
        });

        Schema::table('hr_competency_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_competency_assessments', 'assessor_declared_at')) {
                $table->datetime('assessor_declared_at')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('hr_competency_assessments', 'staff_acknowledged_at')) {
                $table->datetime('staff_acknowledged_at')->nullable()->after('assessor_declared_at');
            }
            if (! Schema::hasColumn('hr_competency_assessments', 'evidence_path')) {
                $table->string('evidence_path')->nullable()->after('staff_acknowledged_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_supervision_notes', function (Blueprint $table) {
            $table->dropColumn(['status', 'cadence']);
        });
        Schema::table('hr_performance_improvement_plans', function (Blueprint $table) {
            $table->dropColumn(['employee_acknowledged', 'employee_acknowledged_at']);
        });
        Schema::table('hr_pip_milestones', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'evidence_path']);
        });
        Schema::table('hr_competencies', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
        Schema::table('hr_competency_assessments', function (Blueprint $table) {
            $table->dropColumn(['assessor_declared_at', 'staff_acknowledged_at', 'evidence_path']);
        });
    }
};
