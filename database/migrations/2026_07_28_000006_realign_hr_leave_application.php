<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoCollision(
            'hr_leave_balances',
            ['user_id', 'leave_type', 'year'],
            'Cannot enforce application leave-balance identity: duplicate user, type, and year rows exist.',
        );
        $this->assertNoCollision(
            'hr_public_holidays',
            ['date', 'region'],
            'Cannot enforce application public-holiday identity: duplicate date and region rows exist.',
        );
        $this->assertNoCollision(
            'staff_time_offs',
            ['hr_leave_request_id'],
            'Cannot enforce one roster projection per leave request: duplicate links exist.',
            requireNonNull: true,
        );
        $this->assertNoCollision(
            'hr_leave_balance_ledgers',
            ['source_type', 'source_id', 'entry_type'],
            'Cannot enforce leave-ledger source identity: duplicate source and entry rows exist.',
            requireNonNull: true,
        );

        Schema::table('hr_leave_balances', function (Blueprint $table): void {
            $table->dropIndex('hr_leave_balances_tenant_id_index');
            $table->dropUnique('hr_leave_balances_tenant_id_user_id_leave_type_year_unique');

            $table->unique(
                ['user_id', 'leave_type', 'year'],
                'hr_leave_balances_user_type_year_uq',
            );
            $table->index(['year', 'leave_type'], 'hr_leave_balances_year_type_idx');
        });

        Schema::table('hr_leave_balance_ledgers', function (Blueprint $table): void {
            $table->dropIndex('hr_leave_balance_ledgers_tenant_id_index');
            $table->dropIndex('hr_leave_ledger_tenant_user_type_year_idx');
            $table->dropIndex('hr_leave_ledger_source_idx');

            $table->index(
                ['user_id', 'leave_type', 'year', 'id'],
                'hr_leave_ledger_user_type_year_idx',
            );
            $table->unique(
                ['source_type', 'source_id', 'entry_type'],
                'hr_leave_ledger_source_entry_uq',
            );
        });

        Schema::table('hr_public_holidays', function (Blueprint $table): void {
            $table->dropIndex('hr_public_holidays_tenant_id_index');

            $table->unique(['date', 'region'], 'hr_public_holidays_date_region_uq');
            $table->index(
                ['is_national', 'date'],
                'hr_public_holidays_national_date_idx',
            );
        });

        Schema::table('staff_time_offs', function (Blueprint $table): void {
            $table->dropIndex('staff_time_offs_tenant_id_index');

            $table->unique('hr_leave_request_id', 'staff_time_offs_leave_request_uq');
            $table->index(
                ['type', 'starts_at', 'ends_at'],
                'staff_time_offs_type_window_idx',
            );
        });

        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->index(
                ['escalated_to', 'status', 'approval_due_at'],
                'hr_leave_requests_approver_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->dropIndex('hr_leave_requests_approver_queue_idx');
        });

        Schema::table('staff_time_offs', function (Blueprint $table): void {
            $table->dropUnique('staff_time_offs_leave_request_uq');
            $table->dropIndex('staff_time_offs_type_window_idx');

            $table->index('tenant_id', 'staff_time_offs_tenant_id_index');
        });

        Schema::table('hr_public_holidays', function (Blueprint $table): void {
            $table->dropUnique('hr_public_holidays_date_region_uq');
            $table->dropIndex('hr_public_holidays_national_date_idx');

            $table->index('tenant_id', 'hr_public_holidays_tenant_id_index');
        });

        Schema::table('hr_leave_balance_ledgers', function (Blueprint $table): void {
            $table->dropIndex('hr_leave_ledger_user_type_year_idx');
            $table->dropUnique('hr_leave_ledger_source_entry_uq');

            $table->index('tenant_id', 'hr_leave_balance_ledgers_tenant_id_index');
            $table->index(
                ['tenant_id', 'user_id', 'leave_type', 'year'],
                'hr_leave_ledger_tenant_user_type_year_idx',
            );
            $table->index(['source_type', 'source_id'], 'hr_leave_ledger_source_idx');
        });

        Schema::table('hr_leave_balances', function (Blueprint $table): void {
            $table->dropUnique('hr_leave_balances_user_type_year_uq');
            $table->dropIndex('hr_leave_balances_year_type_idx');

            $table->index('tenant_id', 'hr_leave_balances_tenant_id_index');
            $table->unique(
                ['tenant_id', 'user_id', 'leave_type', 'year'],
                'hr_leave_balances_tenant_id_user_id_leave_type_year_unique',
            );
        });
    }

    /** @param list<string> $columns */
    private function assertNoCollision(
        string $table,
        array $columns,
        string $message,
        bool $requireNonNull = false,
    ): void {
        $query = DB::table($table)->select($columns);
        if ($requireNonNull) {
            foreach ($columns as $column) {
                $query->whereNotNull($column);
            }
        }

        $collision = $query
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException($message);
        }
    }
};
