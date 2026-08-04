<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENDPOINT_NAME_KEY = 'application_name_key';

    private const ENDPOINT_NAME_UNIQUE = 'hr_webhook_endpoints_name_key_uq';

    private const ENDPOINT_ACTIVE_INDEX = 'hr_webhook_endpoints_active_name_idx';

    private const DELIVERY_EVENT_INDEX = 'hr_webhook_deliveries_event_created_idx';

    private const DELIVERY_RETRY_UNIQUE = 'hr_webhook_deliveries_retry_of_uq';

    private const RULE_NAME_KEY = 'application_name_key';

    private const RULE_NAME_UNIQUE = 'hr_automation_rules_name_key_uq';

    private const RULE_EVENT_INDEX = 'hr_automation_rules_event_active_idx';

    private const RUN_EVENT_INDEX = 'hr_automation_runs_event_executed_idx';

    public function up(): void
    {
        $this->assertApplicationNamesCanBeEnforced('hr_webhook_endpoints', 'webhook endpoint');
        $this->assertApplicationNamesCanBeEnforced('hr_automation_rules', 'automation rule');

        $this->normalizeNames('hr_webhook_endpoints');
        $this->normalizeNames('hr_automation_rules');

        $this->addGeneratedNameIdentity(
            'hr_webhook_endpoints',
            self::ENDPOINT_NAME_KEY,
            self::ENDPOINT_NAME_UNIQUE,
        );
        $this->addGeneratedNameIdentity(
            'hr_automation_rules',
            self::RULE_NAME_KEY,
            self::RULE_NAME_UNIQUE,
        );

        if (! Schema::hasColumn('hr_webhook_deliveries', 'retry_of_id')) {
            Schema::table('hr_webhook_deliveries', function (Blueprint $table): void {
                $table->unsignedBigInteger('retry_of_id')->nullable()->after('endpoint_id');
            });
        }

        $this->addIndex(
            'hr_webhook_deliveries',
            self::DELIVERY_RETRY_UNIQUE,
            fn (Blueprint $table) => $table->unique('retry_of_id', self::DELIVERY_RETRY_UNIQUE),
        );
        $this->addIndex(
            'hr_webhook_endpoints',
            self::ENDPOINT_ACTIVE_INDEX,
            fn (Blueprint $table) => $table->index(['is_active', 'name'], self::ENDPOINT_ACTIVE_INDEX),
        );
        $this->addIndex(
            'hr_webhook_deliveries',
            self::DELIVERY_EVENT_INDEX,
            fn (Blueprint $table) => $table->index(['event_type', 'created_at'], self::DELIVERY_EVENT_INDEX),
        );
        $this->addIndex(
            'hr_automation_rules',
            self::RULE_EVENT_INDEX,
            fn (Blueprint $table) => $table->index(['event_type', 'is_active'], self::RULE_EVENT_INDEX),
        );
        $this->addIndex(
            'hr_automation_runs',
            self::RUN_EVENT_INDEX,
            fn (Blueprint $table) => $table->index(['event_type', 'executed_at'], self::RUN_EVENT_INDEX),
        );

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndex($table, $name, str_ends_with($name, '_unique'));
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
                    str_ends_with($name, '_unique')
                        ? fn (Blueprint $blueprint) => $blueprint->unique($columns, $name)
                        : fn (Blueprint $blueprint) => $blueprint->index($columns, $name),
                );
            }
        }

        $this->dropIndex('hr_automation_runs', self::RUN_EVENT_INDEX);
        $this->dropIndex('hr_automation_rules', self::RULE_EVENT_INDEX);
        $this->dropIndex('hr_webhook_deliveries', self::DELIVERY_EVENT_INDEX);
        $this->dropIndex('hr_webhook_endpoints', self::ENDPOINT_ACTIVE_INDEX);
        $this->dropIndex('hr_webhook_deliveries', self::DELIVERY_RETRY_UNIQUE, unique: true);

        if (Schema::hasColumn('hr_webhook_deliveries', 'retry_of_id')) {
            Schema::table('hr_webhook_deliveries', function (Blueprint $table): void {
                $table->dropColumn('retry_of_id');
            });
        }

        $this->dropGeneratedNameIdentity(
            'hr_automation_rules',
            self::RULE_NAME_KEY,
            self::RULE_NAME_UNIQUE,
        );
        $this->dropGeneratedNameIdentity(
            'hr_webhook_endpoints',
            self::ENDPOINT_NAME_KEY,
            self::ENDPOINT_NAME_UNIQUE,
        );
    }

    private function assertApplicationNamesCanBeEnforced(string $table, string $label): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::table($table)->whereRaw("TRIM(COALESCE(name, '')) = ''")->exists()) {
            throw new RuntimeException("Cannot enforce application {$label} identity while blank names exist.");
        }

        $duplicate = DB::table($table)
            ->selectRaw('LOWER(TRIM(name)) AS canonical_name, COUNT(*) AS duplicate_count')
            ->groupByRaw('LOWER(TRIM(name))')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException("Cannot enforce application {$label} identity while duplicate names exist.");
        }
    }

    private function normalizeNames(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->update(['name' => DB::raw('TRIM(name)')]);
        }
    }

    private function addGeneratedNameIdentity(string $table, string $column, string $unique): void
    {
        if (! Schema::hasColumn($table, $column)) {
            $expression = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? 'lower(trim(`name`))'
                : 'lower(trim(name))';

            Schema::table($table, function (Blueprint $blueprint) use ($column, $expression): void {
                $blueprint->string($column)->nullable()->virtualAs($expression);
            });
        }

        $this->addIndex(
            $table,
            $unique,
            fn (Blueprint $blueprint) => $blueprint->unique($column, $unique),
        );
    }

    private function dropGeneratedNameIdentity(string $table, string $column, string $unique): void
    {
        $this->dropIndex($table, $unique, unique: true);

        if (Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropColumn($column);
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

        Schema::table($table, function (Blueprint $blueprint) use ($name, $unique): void {
            $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name);
        });
    }

    /** @return array<string, array<string, list<string>>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_webhook_endpoints' => [
                'hr_webhook_endpoints_tenant_id_index' => ['tenant_id'],
                'hr_webhook_endpoint_tenant_active_idx' => ['tenant_id', 'is_active'],
                'hr_webhook_endpoint_tenant_name_unique' => ['tenant_id', 'name'],
            ],
            'hr_webhook_deliveries' => [
                'hr_webhook_deliveries_tenant_id_index' => ['tenant_id'],
                'hr_webhook_delivery_tenant_event_idx' => ['tenant_id', 'event_type'],
            ],
            'hr_automation_rules' => [
                'hr_automation_rules_tenant_id_index' => ['tenant_id'],
                'hr_automation_rule_tenant_event_active_idx' => ['tenant_id', 'event_type', 'is_active'],
                'hr_automation_rule_tenant_name_unique' => ['tenant_id', 'name'],
            ],
            'hr_automation_runs' => [
                'hr_automation_runs_tenant_id_index' => ['tenant_id'],
                'hr_automation_run_tenant_event_executed_idx' => ['tenant_id', 'event_type', 'executed_at'],
            ],
        ];
    }
};
