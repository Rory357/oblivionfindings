<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collision = DB::table('hr_payslips')
            ->select(
                'user_id',
                'pay_period_start',
                'pay_period_end',
                DB::raw('COUNT(*) AS duplicate_count'),
            )
            ->groupBy('user_id', 'pay_period_start', 'pay_period_end')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('user_id')
            ->first();
        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce application payslip identity: duplicate employee pay-period rows exist.',
            );
        }

        $this->addIndex(
            'hr_payslips',
            'hr_payslips_user_period_uq',
            fn (Blueprint $table) => $table->unique(
                ['user_id', 'pay_period_start', 'pay_period_end'],
                'hr_payslips_user_period_uq',
            ),
        );
        $this->addIndex(
            'hr_payslips',
            'hr_payslips_status_period_idx',
            fn (Blueprint $table) => $table->index(
                ['status', 'pay_period_end'],
                'hr_payslips_status_period_idx',
            ),
        );

        foreach ([
            'hr_payslips_tenant_id_index',
            'hr_payslips_tenant_id_user_id_pay_period_start_index',
            'hr_payslips_tenant_id_status_index',
        ] as $index) {
            $this->dropIndex('hr_payslips', $index);
        }
    }

    public function down(): void
    {
        $this->addIndex(
            'hr_payslips',
            'hr_payslips_tenant_id_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id'],
                'hr_payslips_tenant_id_index',
            ),
        );
        $this->addIndex(
            'hr_payslips',
            'hr_payslips_tenant_id_user_id_pay_period_start_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id', 'user_id', 'pay_period_start'],
                'hr_payslips_tenant_id_user_id_pay_period_start_index',
            ),
        );
        $this->addIndex(
            'hr_payslips',
            'hr_payslips_tenant_id_status_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id', 'status'],
                'hr_payslips_tenant_id_status_index',
            ),
        );

        $this->dropIndex('hr_payslips', 'hr_payslips_status_period_idx');
        $this->dropIndex('hr_payslips', 'hr_payslips_user_period_uq', true);
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, bool $unique = false): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $unique): void {
            $unique ? $table->dropUnique($name) : $table->dropIndex($name);
        });
    }
};
