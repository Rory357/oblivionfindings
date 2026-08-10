<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BUDGET_PERIOD_KEY = 'application_period_key';

    private const SUGGESTION_ACTIVE_KEY = 'active_dedupe_key';

    public function up(): void
    {
        if (! $this->schemaReady()) {
            return;
        }

        foreach ($this->applicationIdentities() as $identity) {
            $this->assertNoCollision($identity['table'], $identity['columns'], $identity['label']);
        }
        $this->assertNoActiveSuggestionCollision();

        $this->addGeneratedIdentityColumns();

        foreach ($this->applicationIdentities() as $identity) {
            $this->addIndex(
                $identity['table'],
                $identity['application_index'],
                fn (Blueprint $table) => $table->unique(
                    $identity['application_columns'],
                    $identity['application_index'],
                ),
            );
        }

        $this->addIndex(
            'roadmap_suggestions',
            'roadmap_suggestions_active_dedupe_uq',
            fn (Blueprint $table) => $table->unique(
                self::SUGGESTION_ACTIVE_KEY,
                'roadmap_suggestions_active_dedupe_uq',
            ),
        );

        foreach ($this->applicationIndexes() as $index) {
            $this->addIndex(
                $index['table'],
                $index['name'],
                fn (Blueprint $table) => $table->index($index['columns'], $index['name']),
            );
        }

        foreach ($this->applicationIdentities() as $identity) {
            $this->dropIndex($identity['table'], $identity['legacy_index'], unique: true);
        }
    }

    public function down(): void
    {
        if (! $this->schemaReady()) {
            return;
        }

        foreach ($this->applicationIdentities() as $identity) {
            $this->addIndex(
                $identity['table'],
                $identity['legacy_index'],
                fn (Blueprint $table) => $table->unique(
                    $identity['legacy_columns'],
                    $identity['legacy_index'],
                ),
            );
        }

        foreach (array_reverse($this->applicationIndexes()) as $index) {
            $this->dropIndex($index['table'], $index['name']);
        }

        $this->dropIndex('roadmap_suggestions', 'roadmap_suggestions_active_dedupe_uq', unique: true);

        foreach (array_reverse($this->applicationIdentities()) as $identity) {
            $this->dropIndex($identity['table'], $identity['application_index'], unique: true);
        }

        if (Schema::hasColumn('roadmap_suggestions', self::SUGGESTION_ACTIVE_KEY)) {
            Schema::table('roadmap_suggestions', fn (Blueprint $table) => $table->dropColumn(self::SUGGESTION_ACTIVE_KEY));
        }
        if (Schema::hasColumn('roadmap_initiative_budgets', self::BUDGET_PERIOD_KEY)) {
            Schema::table('roadmap_initiative_budgets', fn (Blueprint $table) => $table->dropColumn(self::BUDGET_PERIOD_KEY));
        }
    }

    private function schemaReady(): bool
    {
        foreach ([
            'roadmap_initiative_categories',
            'roadmap_initiatives',
            'roadmap_initiative_site_scopes',
            'roadmap_initiative_site_scope_sites',
            'roadmap_initiative_budgets',
            'roadmap_initiative_risk_links',
            'roadmap_quarterly_plans',
            'roadmap_quarterly_plan_items',
            'roadmap_suggestions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function assertNoCollision(string $table, array $columns, string $label): void
    {
        $duplicate = DB::table($table)
            ->select($columns)
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException("Cannot enforce Roadmap {$label} while duplicate application records exist.");
        }
    }

    private function assertNoActiveSuggestionCollision(): void
    {
        $duplicate = DB::table('roadmap_suggestions')
            ->whereIn('status', ['triage_pending', 'accepted', 'snoozed'])
            ->select('dedupe_key')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('dedupe_key')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Cannot enforce Roadmap active suggestion identity while duplicate application records exist.');
        }
    }

    private function addGeneratedIdentityColumns(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $budgetExpression = match (true) {
            in_array($driver, ['mysql', 'mariadb'], true) => "concat(`initiative_id`, ':', `fiscal_year`, ':', coalesce(`quarter`, 0))",
            $driver === 'pgsql' => "initiative_id::text || ':' || fiscal_year::text || ':' || coalesce(quarter, 0)::text",
            default => "cast(initiative_id as text) || ':' || cast(fiscal_year as text) || ':' || cast(coalesce(quarter, 0) as text)",
        };
        $activeSuggestionExpression = match (true) {
            in_array($driver, ['mysql', 'mariadb'], true) => "if(`status` in ('triage_pending', 'accepted', 'snoozed'), `dedupe_key`, null)",
            default => "case when status in ('triage_pending', 'accepted', 'snoozed') then dedupe_key else null end",
        };

        if (! Schema::hasColumn('roadmap_initiative_budgets', self::BUDGET_PERIOD_KEY)) {
            Schema::table('roadmap_initiative_budgets', function (Blueprint $table) use ($budgetExpression): void {
                $table->string(self::BUDGET_PERIOD_KEY, 128)->nullable()->virtualAs($budgetExpression);
            });
        }
        if (! Schema::hasColumn('roadmap_suggestions', self::SUGGESTION_ACTIVE_KEY)) {
            Schema::table('roadmap_suggestions', function (Blueprint $table) use ($activeSuggestionExpression): void {
                $table->string(self::SUGGESTION_ACTIVE_KEY, 191)->nullable()->virtualAs($activeSuggestionExpression);
            });
        }
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $unique): void {
            $unique ? $table->dropUnique($name) : $table->dropIndex($name);
        });
    }

    /** @return list<array{table: string, columns: list<string>, label: string, application_index: string, application_columns: list<string>, legacy_index: string, legacy_columns: list<string>}> */
    private function applicationIdentities(): array
    {
        return [
            [
                'table' => 'roadmap_initiative_categories',
                'columns' => ['key'],
                'label' => 'category key identity',
                'application_index' => 'roadmap_categories_key_uq',
                'application_columns' => ['key'],
                'legacy_index' => 'rdmp_cat_tenant_key_uq',
                'legacy_columns' => ['tenant_id', 'key'],
            ],
            [
                'table' => 'roadmap_initiatives',
                'columns' => ['code'],
                'label' => 'initiative code identity',
                'application_index' => 'roadmap_initiatives_code_uq',
                'application_columns' => ['code'],
                'legacy_index' => 'roadmap_initiatives_tenant_id_code_unique',
                'legacy_columns' => ['tenant_id', 'code'],
            ],
            [
                'table' => 'roadmap_initiative_site_scopes',
                'columns' => ['initiative_id'],
                'label' => 'initiative Site scope identity',
                'application_index' => 'roadmap_scope_initiative_uq',
                'application_columns' => ['initiative_id'],
                'legacy_index' => 'rdmp_scope_tenant_init_uq',
                'legacy_columns' => ['tenant_id', 'initiative_id'],
            ],
            [
                'table' => 'roadmap_initiative_site_scope_sites',
                'columns' => ['initiative_site_scope_id', 'site_id'],
                'label' => 'Site rollout identity',
                'application_index' => 'roadmap_scope_site_uq',
                'application_columns' => ['initiative_site_scope_id', 'site_id'],
                'legacy_index' => 'roadmap_scope_site_unique',
                'legacy_columns' => ['tenant_id', 'initiative_site_scope_id', 'site_id'],
            ],
            [
                'table' => 'roadmap_initiative_budgets',
                'columns' => ['initiative_id', 'fiscal_year', 'quarter'],
                'label' => 'initiative budget period identity',
                'application_index' => 'roadmap_budget_period_uq',
                'application_columns' => [self::BUDGET_PERIOD_KEY],
                'legacy_index' => 'roadmap_budget_period_unique',
                'legacy_columns' => ['tenant_id', 'initiative_id', 'fiscal_year', 'quarter'],
            ],
            [
                'table' => 'roadmap_initiative_risk_links',
                'columns' => ['initiative_id', 'risk_register_entry_id'],
                'label' => 'initiative risk identity',
                'application_index' => 'roadmap_risk_link_uq',
                'application_columns' => ['initiative_id', 'risk_register_entry_id'],
                'legacy_index' => 'roadmap_risk_link_unique',
                'legacy_columns' => ['tenant_id', 'initiative_id', 'risk_register_entry_id'],
            ],
            [
                'table' => 'roadmap_quarterly_plans',
                'columns' => ['fiscal_year', 'quarter', 'revision_no'],
                'label' => 'quarterly plan revision identity',
                'application_index' => 'roadmap_plan_revision_application_uq',
                'application_columns' => ['fiscal_year', 'quarter', 'revision_no'],
                'legacy_index' => 'roadmap_plan_revision_unique',
                'legacy_columns' => ['tenant_id', 'fiscal_year', 'quarter', 'revision_no'],
            ],
            [
                'table' => 'roadmap_quarterly_plan_items',
                'columns' => ['quarterly_plan_id', 'initiative_id'],
                'label' => 'quarterly plan item identity',
                'application_index' => 'roadmap_plan_item_application_uq',
                'application_columns' => ['quarterly_plan_id', 'initiative_id'],
                'legacy_index' => 'roadmap_plan_item_unique',
                'legacy_columns' => ['tenant_id', 'quarterly_plan_id', 'initiative_id'],
            ],
        ];
    }

    /** @return list<array{table: string, name: string, columns: list<string>}> */
    private function applicationIndexes(): array
    {
        return [
            ['table' => 'roadmap_initiatives', 'name' => 'roadmap_initiatives_status_priority_idx', 'columns' => ['status', 'priority_score']],
            ['table' => 'roadmap_initiatives', 'name' => 'roadmap_initiatives_period_status_idx', 'columns' => ['target_fiscal_year', 'target_quarter', 'status']],
            ['table' => 'roadmap_quarterly_plans', 'name' => 'roadmap_plans_period_status_idx', 'columns' => ['fiscal_year', 'quarter', 'status']],
            ['table' => 'roadmap_suggestions', 'name' => 'roadmap_suggestions_status_seen_idx', 'columns' => ['status', 'last_seen_at']],
            ['table' => 'roadmap_suggestions', 'name' => 'roadmap_suggestions_owner_status_idx', 'columns' => ['triage_owner_id', 'status']],
            ['table' => 'roadmap_decision_requests', 'name' => 'roadmap_decisions_status_due_idx', 'columns' => ['status', 'due_date']],
            ['table' => 'roadmap_initiative_site_scope_sites', 'name' => 'roadmap_scope_site_status_idx', 'columns' => ['site_id', 'status']],
            ['table' => 'roadmap_report_snapshots', 'name' => 'roadmap_reports_type_time_idx', 'columns' => ['report_type', 'generated_at']],
        ];
    }
};
