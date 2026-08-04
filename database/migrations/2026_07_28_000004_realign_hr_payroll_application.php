<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_PROFILE_INDEX = 'hr_payroll_export_profiles_one_default_uq';

    public function up(): void
    {
        $nameCollision = DB::table('hr_payroll_export_profiles')
            ->select('name', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('name')
            ->first();
        if ($nameCollision !== null) {
            throw new RuntimeException(
                'Cannot enforce application payroll-export profile identity: duplicate names exist.',
            );
        }

        if (DB::table('hr_payroll_export_profiles')->where('is_default', true)->count() > 1) {
            throw new RuntimeException(
                'Cannot enforce one application payroll-export default: multiple defaults exist.',
            );
        }

        Schema::table('hr_payroll_export_profiles', function (Blueprint $table): void {
            $table->dropIndex('hr_payroll_export_profiles_tenant_id_index');
            $table->dropUnique('hr_payroll_export_profiles_tenant_id_name_unique');
            $table->dropIndex('hr_payroll_export_profiles_tenant_id_is_default_index');

            $table->unique('name', 'hr_payroll_export_profiles_name_uq');
            $table->index(['is_default', 'name'], 'hr_payroll_export_profiles_default_name_idx');
        });
        $this->addOneDefaultProfileConstraint();

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->dropIndex('hr_payroll_runs_tenant_id_index');
            $table->dropIndex('hr_payroll_runs_tenant_id_period_start_status_index');

            $table->index(
                ['period_start', 'period_end', 'status'],
                'hr_payroll_runs_period_status_idx',
            );
            $table->index(['status', 'period_end'], 'hr_payroll_runs_status_end_idx');
        });

        Schema::table('hr_pay_rate_rules', function (Blueprint $table): void {
            $table->dropIndex('hr_pay_rate_rules_tenant_id_index');
            $table->dropIndex('hr_pay_rate_tenant_active_priority_idx');
            $table->dropIndex('hr_pay_rate_role_site_idx');

            $table->index(['is_active', 'priority'], 'hr_pay_rate_active_priority_idx');
            $table->index(['position_role', 'site_id'], 'hr_pay_rate_role_site_app_idx');
            $table->index(
                ['service_context_id', 'is_active'],
                'hr_pay_rate_context_active_idx',
            );
        });

        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->dropIndex('hr_leave_requests_tenant_id_index');
            $table->dropIndex('hr_leave_requests_tenant_id_status_starts_at_index');

            $table->index(
                ['status', 'leave_type', 'starts_at', 'ends_at'],
                'hr_leave_requests_payroll_window_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->dropIndex('hr_leave_requests_payroll_window_idx');

            $table->index('tenant_id', 'hr_leave_requests_tenant_id_index');
            $table->index(
                ['tenant_id', 'status', 'starts_at'],
                'hr_leave_requests_tenant_id_status_starts_at_index',
            );
        });

        Schema::table('hr_pay_rate_rules', function (Blueprint $table): void {
            $table->dropIndex('hr_pay_rate_active_priority_idx');
            $table->dropIndex('hr_pay_rate_role_site_app_idx');
            $table->dropIndex('hr_pay_rate_context_active_idx');

            $table->index('tenant_id', 'hr_pay_rate_rules_tenant_id_index');
            $table->index(
                ['tenant_id', 'is_active', 'priority'],
                'hr_pay_rate_tenant_active_priority_idx',
            );
            $table->index(
                ['tenant_id', 'position_role', 'site_id'],
                'hr_pay_rate_role_site_idx',
            );
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->dropIndex('hr_payroll_runs_period_status_idx');
            $table->dropIndex('hr_payroll_runs_status_end_idx');

            $table->index('tenant_id', 'hr_payroll_runs_tenant_id_index');
            $table->index(
                ['tenant_id', 'period_start', 'status'],
                'hr_payroll_runs_tenant_id_period_start_status_index',
            );
        });

        $this->dropOneDefaultProfileConstraint();
        Schema::table('hr_payroll_export_profiles', function (Blueprint $table): void {
            $table->dropUnique('hr_payroll_export_profiles_name_uq');
            $table->dropIndex('hr_payroll_export_profiles_default_name_idx');

            $table->index('tenant_id', 'hr_payroll_export_profiles_tenant_id_index');
            $table->unique(
                ['tenant_id', 'name'],
                'hr_payroll_export_profiles_tenant_id_name_unique',
            );
            $table->index(
                ['tenant_id', 'is_default'],
                'hr_payroll_export_profiles_tenant_id_is_default_index',
            );
        });
    }

    private function addOneDefaultProfileConstraint(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('hr_payroll_export_profiles', function (Blueprint $table): void {
                $table->unsignedTinyInteger('application_default_identity')
                    ->nullable()
                    ->storedAs('case when `is_default` = 1 then 1 else null end');
                $table->unique('application_default_identity', self::DEFAULT_PROFILE_INDEX);
            });

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::DEFAULT_PROFILE_INDEX.
                ' ON hr_payroll_export_profiles (is_default) WHERE is_default = TRUE',
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::DEFAULT_PROFILE_INDEX.
                ' ON hr_payroll_export_profiles (is_default) WHERE is_default = 1',
            );
        }
    }

    private function dropOneDefaultProfileConstraint(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('hr_payroll_export_profiles', function (Blueprint $table): void {
                $table->dropUnique(self::DEFAULT_PROFILE_INDEX);
                $table->dropColumn('application_default_identity');
            });

            return;
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::DEFAULT_PROFILE_INDEX);
        }
    }
};
