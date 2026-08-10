<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEPARTMENT_LEGACY_UNIQUE = 'hr_departments_tenant_id_name_unique';

    private const DEPARTMENT_GLOBAL_UNIQUE = 'hr_departments_name_uq';

    private const POSITION_LEGACY_UNIQUE = 'hr_positions_tenant_id_code_unique';

    private const POSITION_GLOBAL_UNIQUE = 'hr_positions_code_uq';

    public function up(): void
    {
        $this->assertNoApplicationIdentityCollisions();

        // Install the application-wide identities and useful application query
        // indexes before removing compatibility indexes. Concurrent writes are
        // therefore never left without a race-safe uniqueness constraint.
        $this->addIndex(
            'hr_departments',
            self::DEPARTMENT_GLOBAL_UNIQUE,
            fn (Blueprint $table) => $table->unique('name', self::DEPARTMENT_GLOBAL_UNIQUE),
        );
        $this->addIndex(
            'hr_departments',
            'hr_departments_active_sort_idx',
            fn (Blueprint $table) => $table->index(
                ['is_active', 'sort_order', 'name'],
                'hr_departments_active_sort_idx',
            ),
        );
        $this->addIndex(
            'hr_positions',
            self::POSITION_GLOBAL_UNIQUE,
            fn (Blueprint $table) => $table->unique('code', self::POSITION_GLOBAL_UNIQUE),
        );
        $this->addIndex(
            'hr_positions',
            'hr_positions_active_department_idx',
            fn (Blueprint $table) => $table->index(
                ['is_active', 'department'],
                'hr_positions_active_department_idx',
            ),
        );

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type]) {
                $this->dropIndex($table, $name, $type);
            }
        }
    }

    public function down(): void
    {
        // Restore the exact compatibility-era indexes before removing the
        // application identities so rollback also preserves write integrity.
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type, $columns]) {
                $this->addIndex($table, $name, function (Blueprint $table) use ($name, $type, $columns): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        foreach ([
            ['hr_positions', 'hr_positions_active_department_idx', 'index'],
            ['hr_positions', self::POSITION_GLOBAL_UNIQUE, 'unique'],
            ['hr_departments', 'hr_departments_active_sort_idx', 'index'],
            ['hr_departments', self::DEPARTMENT_GLOBAL_UNIQUE, 'unique'],
        ] as [$table, $name, $type]) {
            $this->dropIndex($table, $name, $type);
        }
    }

    private function assertNoApplicationIdentityCollisions(): void
    {
        $collisions = [
            'department name' => DB::table('hr_departments')
                ->select('name', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('name')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('name')
                ->first(),
            'position code' => DB::table('hr_positions')
                ->select('code', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('code')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('code')
                ->first(),
        ];

        foreach ($collisions as $identity => $collision) {
            if ($collision !== null) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce application people-configuration identity for %s: duplicate rows exist.',
                    $identity,
                ));
            }
        }
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_departments' => [
                [self::DEPARTMENT_LEGACY_UNIQUE, 'unique', ['tenant_id', 'name']],
                ['hr_departments_tenant_id_index', 'index', ['tenant_id']],
            ],
            'hr_positions' => [
                [self::POSITION_LEGACY_UNIQUE, 'unique', ['tenant_id', 'code']],
                ['hr_positions_tenant_id_index', 'index', ['tenant_id']],
                ['hr_positions_tenant_id_is_active_index', 'index', ['tenant_id', 'is_active']],
                ['hr_positions_tenant_id_department_index', 'index', ['tenant_id', 'department']],
            ],
        ];
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, string $type): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $type): void {
            $type === 'unique'
                ? $table->dropUnique($name)
                : $table->dropIndex($name);
        });
    }
};
