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
            'benefit plan name' => DB::table('hr_benefit_plans')
                ->select('name', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('name')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('name')
                ->first(),
            'employee benefit plan enrollment' => DB::table('hr_benefit_enrollments')
                ->select('employee_profile_id', 'benefit_plan_id', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('employee_profile_id', 'benefit_plan_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('employee_profile_id')
                ->orderBy('benefit_plan_id')
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
            'hr_benefit_plans' => [
                ['hr_benefit_plans_name_uq', 'unique', ['name']],
                ['hr_benefit_plans_active_type_name_idx', 'index', ['is_active', 'type', 'name']],
            ],
            'hr_benefit_enrollments' => [
                ['hr_benefit_enrollments_profile_plan_uq', 'unique', ['employee_profile_id', 'benefit_plan_id']],
                ['hr_benefit_enrollments_plan_status_idx', 'index', ['benefit_plan_id', 'status']],
                ['hr_benefit_enrollments_profile_status_idx', 'index', ['employee_profile_id', 'status']],
            ],
        ];
    }

    /** @return array<string, list<array{string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_benefit_plans' => [
                ['hr_benefit_plans_tenant_id_index', ['tenant_id']],
                ['hr_benefit_plans_tenant_id_type_index', ['tenant_id', 'type']],
            ],
            'hr_benefit_enrollments' => [
                ['hr_benefit_enrollments_tenant_id_index', ['tenant_id']],
                ['hr_benefit_enroll_tenant_emp', ['tenant_id', 'employee_profile_id']],
                ['hr_benefit_enroll_tenant_plan', ['tenant_id', 'benefit_plan_id']],
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
