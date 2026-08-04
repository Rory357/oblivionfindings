<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SKILL_IDENTITY = 'hr_skills_category_name_uq';

    private const SKILL_READ_INDEX = 'hr_skills_active_category_name_idx';

    private const ASSESSMENT_READ_INDEX = 'hr_emp_skills_skill_level_profile_idx';

    public function up(): void
    {
        $collision = DB::table('hr_skills')
            ->select('category', 'name', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('category', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('category')
            ->orderBy('name')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce application skill identity: duplicate category and name rows exist.',
            );
        }

        $this->addIndex(
            'hr_skills',
            self::SKILL_IDENTITY,
            fn (Blueprint $table) => $table->unique(
                ['category', 'name'],
                self::SKILL_IDENTITY,
            ),
        );
        $this->addIndex(
            'hr_skills',
            self::SKILL_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['is_active', 'category', 'name'],
                self::SKILL_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_employee_skills',
            self::ASSESSMENT_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['skill_id', 'proficiency_level', 'employee_profile_id'],
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
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $columns]) {
                $this->addIndex(
                    $table,
                    $name,
                    fn (Blueprint $table) => $table->index($columns, $name),
                );
            }
        }

        $this->dropIndex('hr_employee_skills', self::ASSESSMENT_READ_INDEX);
        $this->dropIndex('hr_skills', self::SKILL_READ_INDEX);
        $this->dropIndex('hr_skills', self::SKILL_IDENTITY, unique: true);
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
            'hr_skills' => [
                ['hr_skills_tenant_id_category_index', ['tenant_id', 'category']],
                ['hr_skills_tenant_id_index', ['tenant_id']],
            ],
            'hr_employee_skills' => [
                ['hr_employee_skills_tenant_id_index', ['tenant_id']],
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
