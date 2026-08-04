<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collisions = [
            'salary band role, name and effective date' => DB::table('hr_salary_bands')
                ->select('position_role', 'band_name', 'effective_from', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('position_role', 'band_name', 'effective_from')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('position_role')
                ->orderBy('band_name')
                ->orderBy('effective_from')
                ->first(),
            'compensation review employee' => DB::table('hr_compensation_review_items')
                ->select('compensation_review_id', 'employee_profile_id', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('compensation_review_id', 'employee_profile_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('compensation_review_id')
                ->orderBy('employee_profile_id')
                ->first(),
        ];
        foreach ($collisions as $identity => $collision) {
            if ($collision !== null) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce application %s identity: duplicate rows exist.',
                    $identity,
                ));
            }
        }

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type, $columns]) {
                $this->addIndex($table, $name, function (Blueprint $table) use ($name, $type, $columns): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name]) {
                $this->dropIndex($table, $name);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $columns]) {
                $this->addIndex(
                    $table,
                    $name,
                    fn (Blueprint $table) => $table->index($columns, $name),
                );
            }
        }

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach (array_reverse($indexes) as [$name, $type]) {
                $this->dropIndex($table, $name, $type === 'unique');
            }
        }
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function applicationIndexes(): array
    {
        return [
            'hr_salary_bands' => [
                ['hr_salary_bands_role_name_effective_uq', 'unique', ['position_role', 'band_name', 'effective_from']],
                ['hr_salary_bands_active_role_effective_idx', 'index', ['is_active', 'position_role', 'effective_from']],
            ],
            'hr_compensation_history' => [
                ['hr_compensation_history_profile_effective_idx', 'index', ['employee_profile_id', 'effective_date']],
            ],
            'hr_compensation_reviews' => [
                ['hr_compensation_reviews_status_effective_idx', 'index', ['status', 'effective_date']],
                ['hr_compensation_reviews_created_status_idx', 'index', ['created_by', 'status']],
            ],
            'hr_compensation_review_items' => [
                ['hr_compensation_review_items_review_profile_uq', 'unique', ['compensation_review_id', 'employee_profile_id']],
                ['hr_compensation_review_items_profile_status_idx', 'index', ['employee_profile_id', 'status']],
            ],
            'hr_bonus_payments' => [
                ['hr_bonus_payments_status_payment_idx', 'index', ['status', 'payment_date']],
                ['hr_bonus_payments_profile_status_idx', 'index', ['employee_profile_id', 'status']],
            ],
        ];
    }

    /** @return array<string, list<array{string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_salary_bands' => [
                ['hr_salary_bands_tenant_id_index', ['tenant_id']],
                ['hr_salary_bands_tenant_id_position_role_index', ['tenant_id', 'position_role']],
            ],
            'hr_compensation_history' => [
                ['hr_compensation_history_tenant_id_index', ['tenant_id']],
                ['hr_comp_hist_tenant_emp_date', ['tenant_id', 'employee_profile_id', 'effective_date']],
            ],
            'hr_compensation_reviews' => [
                ['hr_compensation_reviews_tenant_id_index', ['tenant_id']],
            ],
            'hr_bonus_payments' => [
                ['hr_bonus_payments_tenant_id_index', ['tenant_id']],
                ['hr_bonus_payments_tenant_id_status_index', ['tenant_id', 'status']],
            ],
        ];
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
