<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CASE_STATUS_INDEX = 'hr_cases_status_severity_opened_idx';

    private const CASE_OWNER_INDEX = 'hr_cases_assignee_status_opened_idx';

    private const ACTION_SLA_INDEX = 'hr_disciplinary_stage_response_deadline_idx';

    private const ACTION_CASE_INDEX = 'hr_disciplinary_case_stage_idx';

    public function up(): void
    {
        $this->addIndex(
            'hr_cases',
            self::CASE_STATUS_INDEX,
            fn (Blueprint $table) => $table->index(
                ['status', 'severity', 'opened_at'],
                self::CASE_STATUS_INDEX,
            ),
        );
        $this->addIndex(
            'hr_cases',
            self::CASE_OWNER_INDEX,
            fn (Blueprint $table) => $table->index(
                ['assigned_to', 'status', 'opened_at'],
                self::CASE_OWNER_INDEX,
            ),
        );
        $this->addIndex(
            'hr_disciplinary_actions',
            self::ACTION_SLA_INDEX,
            fn (Blueprint $table) => $table->index(
                ['stage', 'response_deadline'],
                self::ACTION_SLA_INDEX,
            ),
        );
        $this->addIndex(
            'hr_disciplinary_actions',
            self::ACTION_CASE_INDEX,
            fn (Blueprint $table) => $table->index(
                ['case_id', 'stage'],
                self::ACTION_CASE_INDEX,
            ),
        );

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndex($table, $name);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex(
                    $table,
                    $name,
                    fn (Blueprint $blueprint) => $blueprint->index($columns, $name),
                );
            }
        }

        $this->dropIndex('hr_disciplinary_actions', self::ACTION_CASE_INDEX);
        $this->dropIndex('hr_disciplinary_actions', self::ACTION_SLA_INDEX);
        $this->dropIndex('hr_cases', self::CASE_OWNER_INDEX);
        $this->dropIndex('hr_cases', self::CASE_STATUS_INDEX);
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }

    /** @return array<string, array<string, list<string>>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_cases' => [
                'hr_cases_tenant_id_status_index' => ['tenant_id', 'status'],
                'hr_cases_tenant_id_index' => ['tenant_id'],
            ],
            'hr_disciplinary_actions' => [
                'hr_disciplinary_actions_tenant_id_index' => ['tenant_id'],
            ],
        ];
    }
};
