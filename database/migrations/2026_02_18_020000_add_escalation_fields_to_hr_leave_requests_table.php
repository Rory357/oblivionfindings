<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_leave_requests', 'approval_due_at')) {
                $table->dateTime('approval_due_at')->nullable()->after('submitted_at');
                $table->index(['status', 'approval_due_at'], 'hr_leave_requests_status_due_idx');
            }

            if (! Schema::hasColumn('hr_leave_requests', 'escalation_level')) {
                $table->unsignedInteger('escalation_level')->default(1)->after('escalated_to');
            }

            if (! Schema::hasColumn('hr_leave_requests', 'escalated_at')) {
                $table->dateTime('escalated_at')->nullable()->after('escalation_level');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('hr_leave_requests', 'approval_due_at')) {
                $table->dropIndex('hr_leave_requests_status_due_idx');
                $table->dropColumn('approval_due_at');
            }

            if (Schema::hasColumn('hr_leave_requests', 'escalation_level')) {
                $table->dropColumn('escalation_level');
            }

            if (Schema::hasColumn('hr_leave_requests', 'escalated_at')) {
                $table->dropColumn('escalated_at');
            }
        });
    }
};

