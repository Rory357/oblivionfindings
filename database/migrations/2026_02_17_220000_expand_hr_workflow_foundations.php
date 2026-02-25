<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_leave_approval_chains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('approval_level')->default(1);
            $table->unsignedInteger('escalation_after_hours')->default(48);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'approval_level'], 'hr_leave_chain_tenant_user_level_unique');
            $table->index(['tenant_id', 'user_id', 'is_active'], 'hr_leave_chain_tenant_user_active_idx');
        });

        Schema::create('hr_leave_balance_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('leave_type');
            $table->integer('year');
            $table->string('entry_type');
            $table->decimal('hours_delta', 10, 2);
            $table->decimal('balance_hours_before', 10, 2)->default(0);
            $table->decimal('balance_hours_after', 10, 2)->default(0);
            $table->decimal('used_hours_before', 10, 2)->default(0);
            $table->decimal('used_hours_after', 10, 2)->default(0);
            $table->decimal('pending_hours_before', 10, 2)->default(0);
            $table->decimal('pending_hours_after', 10, 2)->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'leave_type', 'year'], 'hr_leave_ledger_tenant_user_type_year_idx');
            $table->index(['source_type', 'source_id'], 'hr_leave_ledger_source_idx');
        });

        Schema::create('hr_pay_rate_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->string('position_role')->nullable();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('service_context_id')->nullable()->constrained('service_contexts')->nullOnDelete();
            $table->boolean('applies_on_public_holiday')->nullable();
            $table->boolean('applies_on_sleepover')->nullable();
            $table->boolean('applies_on_call')->nullable();
            $table->decimal('regular_multiplier', 5, 2)->default(1.00);
            $table->decimal('overtime_multiplier', 5, 2)->default(1.50);
            $table->decimal('public_holiday_multiplier', 5, 2)->default(1.50);
            $table->decimal('sleepover_flat_rate', 10, 2)->default(0);
            $table->decimal('on_call_hourly_rate', 10, 2)->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'priority'], 'hr_pay_rate_tenant_active_priority_idx');
            $table->index(['tenant_id', 'position_role', 'site_id'], 'hr_pay_rate_role_site_idx');
        });

        Schema::table('hr_onboarding_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_onboarding_tasks', 'due_date')) {
                $table->date('due_date')->nullable()->after('status');
            }
            if (! Schema::hasColumn('hr_onboarding_tasks', 'dependency_task_ids')) {
                $table->json('dependency_task_ids')->nullable()->after('due_date');
            }
        });

        Schema::create('hr_offboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_checklist_id')->constrained('hr_offboarding_checklists')->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_to_role')->nullable();
            $table->string('status')->default('pending');
            $table->date('due_date')->nullable();
            $table->json('dependency_task_ids')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evidence_path')->nullable();
            $table->boolean('sign_off_required')->default(false);
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('signed_off_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['offboarding_checklist_id', 'status'], 'hr_offboarding_tasks_checklist_status_idx');
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_payroll_runs', 'total_gross')) {
                $table->decimal('total_gross', 12, 2)->default(0)->after('total_hours');
            }
            if (! Schema::hasColumn('hr_payroll_runs', 'validation_errors')) {
                $table->json('validation_errors')->nullable()->after('notes');
            }
        });

        Schema::table('hr_payroll_run_items', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_payroll_run_items', 'base_hourly_rate')) {
                $table->decimal('base_hourly_rate', 10, 2)->nullable()->after('timesheet_ids');
            }
            if (! Schema::hasColumn('hr_payroll_run_items', 'overtime_multiplier')) {
                $table->decimal('overtime_multiplier', 5, 2)->default(1.50)->after('base_hourly_rate');
            }
            if (! Schema::hasColumn('hr_payroll_run_items', 'public_holiday_multiplier')) {
                $table->decimal('public_holiday_multiplier', 5, 2)->default(1.50)->after('overtime_multiplier');
            }
            if (! Schema::hasColumn('hr_payroll_run_items', 'sleepover_rate')) {
                $table->decimal('sleepover_rate', 10, 2)->default(0)->after('public_holiday_multiplier');
            }
            if (! Schema::hasColumn('hr_payroll_run_items', 'on_call_rate')) {
                $table->decimal('on_call_rate', 10, 2)->default(0)->after('sleepover_rate');
            }
            if (! Schema::hasColumn('hr_payroll_run_items', 'rate_breakdown')) {
                $table->json('rate_breakdown')->nullable()->after('allowances');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_run_items', function (Blueprint $table) {
            $columns = [
                'base_hourly_rate',
                'overtime_multiplier',
                'public_holiday_multiplier',
                'sleepover_rate',
                'on_call_rate',
                'rate_breakdown',
            ];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('hr_payroll_run_items', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $columns = ['total_gross', 'validation_errors'];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('hr_payroll_runs', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::dropIfExists('hr_offboarding_tasks');

        Schema::table('hr_onboarding_tasks', function (Blueprint $table) {
            $columns = ['due_date', 'dependency_task_ids'];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('hr_onboarding_tasks', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::dropIfExists('hr_pay_rate_rules');
        Schema::dropIfExists('hr_leave_balance_ledgers');
        Schema::dropIfExists('hr_leave_approval_chains');
    }
};

