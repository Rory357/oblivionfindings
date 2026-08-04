<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPETENCY_UNIQUE = 'hr_competencies_name_uq';

    private const COMPETENCY_READ_INDEX = 'hr_competencies_active_sort_idx';

    private const ASSESSMENT_READ_INDEX = 'hr_comp_assess_profile_date_idx';

    public function up(): void
    {
        $collision = DB::table('hr_competencies')
            ->select('name', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('name')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce application competency name identity: duplicate rows exist.',
            );
        }

        // Install application-shaped identities and read paths before removing
        // the obsolete compatibility-column indexes.
        $this->addIndex(
            'hr_competencies',
            self::COMPETENCY_UNIQUE,
            fn (Blueprint $table) => $table->unique('name', self::COMPETENCY_UNIQUE),
        );
        $this->addIndex(
            'hr_competencies',
            self::COMPETENCY_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['is_active', 'sort_order', 'name'],
                self::COMPETENCY_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_competency_assessments',
            self::ASSESSMENT_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['employee_profile_id', 'assessment_date'],
                self::ASSESSMENT_READ_INDEX,
            ),
        );

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name]) {
                $this->dropIndex($table, $name);
            }
        }
    }

    public function down(): void
    {
        // Restore compatibility indexes first so rollback never leaves the
        // historical storage contract without its original lookup path.
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $columns]) {
                $this->addIndex(
                    $table,
                    $name,
                    fn (Blueprint $table) => $table->index($columns, $name),
                );
            }
        }

        $this->dropIndex('hr_competency_assessments', self::ASSESSMENT_READ_INDEX);
        $this->dropIndex('hr_competencies', self::COMPETENCY_READ_INDEX);
        $this->dropIndex('hr_competencies', self::COMPETENCY_UNIQUE, unique: true);
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    /** @return array<string, list<array{string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_competencies' => [
                ['hr_competencies_tenant_id_index', ['tenant_id']],
            ],
            'hr_competency_assessments' => [
                ['hr_competency_assessments_tenant_id_index', ['tenant_id']],
            ],
        ];
    }

    private function dropIndex(string $table, string $name, bool $unique = false): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $unique): void {
            $unique
                ? $table->dropUnique($name)
                : $table->dropIndex($name);
        });
    }
};
