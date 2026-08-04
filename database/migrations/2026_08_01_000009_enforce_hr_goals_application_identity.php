<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CYCLE_NAME_UNIQUE = 'hr_goal_cycles_name_uq';

    private const CYCLE_READ_INDEX = 'hr_goal_cycles_status_dates_idx';

    private const TEMPLATE_NAME_UNIQUE = 'hr_goal_templates_name_uq';

    private const TEMPLATE_READ_INDEX = 'hr_goal_templates_active_name_idx';

    private const GOAL_OWNER_READ_INDEX = 'hr_goals_user_status_due_idx';

    private const GOAL_CYCLE_READ_INDEX = 'hr_goals_cycle_type_status_idx';

    private const GOAL_PARENT_READ_INDEX = 'hr_goals_parent_status_idx';

    private const GOAL_CONFIDENCE_READ_INDEX = 'hr_goals_confidence_status_idx';

    private const KEY_RESULT_OWNER_READ_INDEX = 'hr_key_results_owner_status_due_idx';

    public function up(): void
    {
        $this->assertNoCollision('hr_goal_cycles', 'name', 'goal cycle name');
        $this->assertNoCollision('hr_goal_templates', 'name', 'goal template name');

        // Install application identities and useful read paths before removing
        // compatibility-leading indexes.
        $this->addIndex(
            'hr_goal_cycles',
            self::CYCLE_NAME_UNIQUE,
            fn (Blueprint $table) => $table->unique('name', self::CYCLE_NAME_UNIQUE),
        );
        $this->addIndex(
            'hr_goal_cycles',
            self::CYCLE_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['status', 'starts_at', 'ends_at'],
                self::CYCLE_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_goal_templates',
            self::TEMPLATE_NAME_UNIQUE,
            fn (Blueprint $table) => $table->unique('name', self::TEMPLATE_NAME_UNIQUE),
        );
        $this->addIndex(
            'hr_goal_templates',
            self::TEMPLATE_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['is_active', 'name'],
                self::TEMPLATE_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_goals',
            self::GOAL_OWNER_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['user_id', 'status', 'due_date'],
                self::GOAL_OWNER_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_goals',
            self::GOAL_CYCLE_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['cycle_id', 'goal_type', 'status'],
                self::GOAL_CYCLE_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_goals',
            self::GOAL_PARENT_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['parent_goal_id', 'status'],
                self::GOAL_PARENT_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_goals',
            self::GOAL_CONFIDENCE_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['confidence', 'status'],
                self::GOAL_CONFIDENCE_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_key_results',
            self::KEY_RESULT_OWNER_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['owner_id', 'status', 'due_date'],
                self::KEY_RESULT_OWNER_READ_INDEX,
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
        // Restore the exact compatibility indexes first so rollback never
        // leaves the historical required-column contract without its paths.
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $columns]) {
                $this->addIndex(
                    $table,
                    $name,
                    fn (Blueprint $table) => $table->index($columns, $name),
                );
            }
        }

        $this->dropIndex('hr_key_results', self::KEY_RESULT_OWNER_READ_INDEX);
        $this->dropIndex('hr_goals', self::GOAL_CONFIDENCE_READ_INDEX);
        $this->dropIndex('hr_goals', self::GOAL_PARENT_READ_INDEX);
        $this->dropIndex('hr_goals', self::GOAL_CYCLE_READ_INDEX);
        $this->dropIndex('hr_goals', self::GOAL_OWNER_READ_INDEX);
        $this->dropIndex('hr_goal_templates', self::TEMPLATE_READ_INDEX);
        $this->dropIndex('hr_goal_templates', self::TEMPLATE_NAME_UNIQUE, unique: true);
        $this->dropIndex('hr_goal_cycles', self::CYCLE_READ_INDEX);
        $this->dropIndex('hr_goal_cycles', self::CYCLE_NAME_UNIQUE, unique: true);
    }

    private function assertNoCollision(string $table, string $column, string $identity): void
    {
        $collision = DB::table($table)
            ->select($column, DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->orderBy($column)
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                "Cannot enforce application {$identity} identity: duplicate rows exist.",
            );
        }
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
            'hr_goals' => [
                ['hr_goals_tenant_id_index', ['tenant_id']],
                ['hr_goals_tenant_id_user_id_index', ['tenant_id', 'user_id']],
                ['hr_goals_tenant_id_status_index', ['tenant_id', 'status']],
                ['hr_goals_tenant_id_goal_type_index', ['tenant_id', 'goal_type']],
                ['hr_goals_tenant_id_cycle_id_index', ['tenant_id', 'cycle_id']],
                ['hr_goals_tenant_id_confidence_index', ['tenant_id', 'confidence']],
            ],
            'hr_goal_cycles' => [
                ['hr_goal_cycles_tenant_id_index', ['tenant_id']],
                ['hr_goal_cycles_tenant_id_status_index', ['tenant_id', 'status']],
            ],
            'hr_key_results' => [
                ['hr_key_results_tenant_id_index', ['tenant_id']],
            ],
            'hr_goal_templates' => [
                ['hr_goal_templates_tenant_id_index', ['tenant_id']],
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
