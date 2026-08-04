<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collision = DB::table('hr_expense_claims')
            ->select('claim_number', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('claim_number')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('claim_number')
            ->first();
        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce application expense claim number identity: duplicate rows exist.',
            );
        }

        $this->addIndex(
            'hr_expense_claims',
            'hr_expense_claims_claim_number_uq',
            fn (Blueprint $table) => $table->unique(
                ['claim_number'],
                'hr_expense_claims_claim_number_uq',
            ),
        );
        $this->addIndex(
            'hr_expense_claims',
            'hr_expense_claims_status_submitted_idx',
            fn (Blueprint $table) => $table->index(
                ['status', 'submitted_at'],
                'hr_expense_claims_status_submitted_idx',
            ),
        );
        $this->addIndex(
            'hr_expense_claims',
            'hr_expense_claims_user_status_created_idx',
            fn (Blueprint $table) => $table->index(
                ['user_id', 'status', 'created_at'],
                'hr_expense_claims_user_status_created_idx',
            ),
        );

        foreach ([
            ['hr_expense_claims_tenant_id_index', false],
            ['hr_expense_claims_tenant_id_claim_number_unique', true],
            ['hr_expense_claims_tenant_id_status_index', false],
        ] as [$index, $unique]) {
            $this->dropIndex('hr_expense_claims', $index, $unique);
        }
    }

    public function down(): void
    {
        $this->addIndex(
            'hr_expense_claims',
            'hr_expense_claims_tenant_id_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id'],
                'hr_expense_claims_tenant_id_index',
            ),
        );
        $this->addIndex(
            'hr_expense_claims',
            'hr_expense_claims_tenant_id_claim_number_unique',
            fn (Blueprint $table) => $table->unique(
                ['tenant_id', 'claim_number'],
                'hr_expense_claims_tenant_id_claim_number_unique',
            ),
        );
        $this->addIndex(
            'hr_expense_claims',
            'hr_expense_claims_tenant_id_status_index',
            fn (Blueprint $table) => $table->index(
                ['tenant_id', 'status'],
                'hr_expense_claims_tenant_id_status_index',
            ),
        );

        $this->dropIndex('hr_expense_claims', 'hr_expense_claims_user_status_created_idx');
        $this->dropIndex('hr_expense_claims', 'hr_expense_claims_status_submitted_idx');
        $this->dropIndex('hr_expense_claims', 'hr_expense_claims_claim_number_uq', true);
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
