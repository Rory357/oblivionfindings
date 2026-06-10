<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wages, time, holiday/leave, and employment-case records must survive
     * worker termination. Deactivation is the supported termination path;
     * hard-deleting a user with statutory HR records should be blocked.
     */
    public function up(): void
    {
        $this->replaceUserForeignKey('hr_leave_requests', 'user_id', 'restrict');
        $this->replaceUserForeignKey('hr_leave_balances', 'user_id', 'restrict');
        $this->replaceUserForeignKey('hr_payroll_run_items', 'user_id', 'restrict');
        $this->replaceUserForeignKey('hr_payslips', 'user_id', 'restrict');
        $this->replaceUserForeignKey('hr_cases', 'user_id', 'restrict');
        $this->replaceUserForeignKey('hr_disciplinary_actions', 'employee_user_id', 'restrict');
    }

    public function down(): void
    {
        $this->replaceUserForeignKey('hr_leave_requests', 'user_id', 'cascade');
        $this->replaceUserForeignKey('hr_leave_balances', 'user_id', 'cascade');
        $this->replaceUserForeignKey('hr_payroll_run_items', 'user_id', 'cascade');
        $this->replaceUserForeignKey('hr_payslips', 'user_id', 'cascade');
        $this->replaceUserForeignKey('hr_cases', 'user_id', 'cascade');
        $this->replaceUserForeignKey('hr_disciplinary_actions', 'employee_user_id', 'cascade');
    }

    private function replaceUserForeignKey(string $table, string $column, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $onDelete) {
            $table->dropForeign([$column]);

            $foreign = $table->foreign($column)->references('id')->on('users');

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }
};
