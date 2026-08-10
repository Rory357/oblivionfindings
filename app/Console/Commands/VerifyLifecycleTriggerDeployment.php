<?php

namespace App\Console\Commands;

use App\Support\Database\LifecycleTriggerDeploymentGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class VerifyLifecycleTriggerDeployment extends Command
{
    protected $signature = 'database:verify-lifecycle-triggers
        {phase : preflight or postflight}
        {--database= : Configured migration connection; defaults to the application default}
        {--json : Emit one value-free JSON report}';

    protected $description = 'Read-only production boundary for the retained lifecycle trigger migrations';

    public function handle(LifecycleTriggerDeploymentGuard $guard): int
    {
        $phase = strtolower((string) $this->argument('phase'));
        if (! in_array($phase, ['preflight', 'postflight'], true)) {
            return $this->finish($phase, ['invalid_phase']);
        }

        try {
            $connectionName = $this->option('database') ?: config('database.default');
            $connection = DB::connection($connectionName);

            if ($connection->getDriverName() !== 'mysql') {
                return $this->finish($phase, ['mysql_connection_required']);
            }

            $sourceResult = $this->sourceRoster($guard);
            $sourceRoster = $sourceResult['roster'];
            $sourceErrors = $guard->sourceRosterErrors(
                $sourceRoster,
                $sourceResult['raw_count'],
                $sourceResult['duplicate_names'],
            );
            $server = $connection->selectOne(
                'SELECT VERSION() AS version, @@version_comment AS version_comment, '
                .'@@GLOBAL.log_bin AS log_bin, '
                .'@@GLOBAL.log_bin_trust_function_creators AS log_bin_trust_function_creators, '
                .'CURRENT_USER() AS current_user_name, DATABASE() AS database_name',
            );

            if ($server === null) {
                return $this->finish($phase, ['database_capability_query_failed']);
            }

            $database = (string) $server->database_name;
            $currentUser = (string) $server->current_user_name;
            $binaryLogging = (bool) $server->log_bin;
            $trustFunctionCreators = (bool) $server->log_bin_trust_function_creators;
            $errors = $sourceErrors;

            if ($database === '' || $currentUser === '') {
                $errors[] = 'configured_database_identity_missing';
            }

            $supportedMySql = $guard->supportsMySqlVersion(
                (string) $server->version,
                (string) $server->version_comment,
            );
            if (! $supportedMySql) {
                $errors[] = 'unsupported_mysql_version';
            }

            if (! $guard->binaryLogAllowsTriggerCreation($binaryLogging, $trustFunctionCreators)) {
                $errors[] = 'binary_log_trigger_trust_disabled';
            }

            if ($supportedMySql) {
                $grantStatements = $this->effectiveGrantStatements($connection);
                $schemaPrivileges = $guard->schemaPrivileges($grantStatements, $database);
                $missingPrivileges = array_values(array_diff(
                    LifecycleTriggerDeploymentGuard::REQUIRED_SCHEMA_PRIVILEGES,
                    $schemaPrivileges,
                ));
                if ($missingPrivileges !== []) {
                    $errors[] = 'missing_schema_privileges:'.implode(',', $missingPrivileges);
                }
                $prohibitedPrivileges = $guard->prohibitedGlobalPrivileges($grantStatements);
                if ($prohibitedPrivileges !== []) {
                    $errors[] = 'prohibited_global_privileges:'.implode(',', $prohibitedPrivileges);
                }
            }

            $applied = $this->appliedGoalMigrations($connection);
            $liveRows = $this->liveTriggerRows($connection, $database, $guard->targetTables());
            $requiredMigrations = array_keys(array_filter($applied));
            $errors = array_merge(
                $errors,
                $guard->liveRosterErrors($liveRows, $sourceRoster, $requiredMigrations, $currentUser),
                $this->migrationStateErrors($connection, $database, $applied, $phase),
            );

            return $this->finish(
                $phase,
                array_values(array_unique($errors)),
                $applied,
                $binaryLogging,
                $trustFunctionCreators,
                count($liveRows),
            );
        } catch (Throwable) {
            return $this->finish($phase, ['database_preflight_query_failed']);
        }
    }

    /**
     * @return array{
     *     roster: array<string, array{migration: string, table: string, timing: string, event: string, action_hash: string}>,
     *     raw_count: int,
     *     duplicate_names: list<string>
     * }
     */
    private function sourceRoster(LifecycleTriggerDeploymentGuard $guard): array
    {
        return $guard->sourceRosterResult([
            LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION => (string) file_get_contents(
                database_path('migrations/'.LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION.'.php'),
            ),
            LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION => (string) file_get_contents(
                database_path('migrations/'.LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION.'.php'),
            ),
        ]);
    }

    /** @return list<string> */
    private function effectiveGrantStatements(Connection $connection): array
    {
        $roles = $connection->select(
            'SELECT ROLE_NAME AS role_name, ROLE_HOST AS role_host '
            .'FROM information_schema.ENABLED_ROLES ORDER BY ROLE_NAME, ROLE_HOST',
        );

        if ($roles === []) {
            return $this->grantStatementsFromRows($connection->select('SHOW GRANTS FOR CURRENT_USER()'));
        }

        $using = implode(', ', array_map(
            fn (object $role): string => $connection->getPdo()->quote((string) $role->role_name)
                .'@'.$connection->getPdo()->quote((string) $role->role_host),
            $roles,
        ));

        return array_values(array_unique($this->grantStatementsFromRows(
            $connection->select("SHOW GRANTS FOR CURRENT_USER() USING {$using}"),
        )));
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<string>
     */
    private function grantStatementsFromRows(array $rows): array
    {
        return array_values(array_filter(array_map(
            fn (object $row): string => (string) (array_values((array) $row)[0] ?? ''),
            $rows,
        )));
    }

    /** @return array<string, bool> */
    private function appliedGoalMigrations(Connection $connection): array
    {
        $migrations = [
            LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION => false,
            LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION => false,
        ];
        $table = $this->migrationTableName();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return $migrations;
        }

        foreach ($connection->table($table)
            ->whereIn('migration', array_keys($migrations))
            ->pluck('migration') as $migration) {
            $migrations[(string) $migration] = true;
        }

        return $migrations;
    }

    private function migrationTableName(): string
    {
        $migrations = config('database.migrations', 'migrations');

        return is_array($migrations)
            ? ($migrations['table'] ?? 'migrations')
            : $migrations;
    }

    /**
     * @param  list<string>  $tables
     * @return list<array{name: string, table: string, timing: string, event: string, action: string, definer: string}>
     */
    private function liveTriggerRows(Connection $connection, string $database, array $tables): array
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $rows = $connection->select(
            'SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS `table`, ACTION_TIMING AS timing, '
            .'EVENT_MANIPULATION AS event, ACTION_STATEMENT AS action, DEFINER AS definer '
            .'FROM information_schema.TRIGGERS '
            ."WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE IN ({$placeholders}) "
            .'ORDER BY TRIGGER_NAME',
            [$database, ...$tables],
        );

        return array_map(fn (object $row): array => [
            'name' => (string) $row->name,
            'table' => (string) $row->table,
            'timing' => (string) $row->timing,
            'event' => (string) $row->event,
            'action' => (string) $row->action,
            'definer' => (string) $row->definer,
        ], $rows);
    }

    /**
     * @param  array<string, bool>  $applied
     * @return list<string>
     */
    private function migrationStateErrors(
        Connection $connection,
        string $database,
        array $applied,
        string $phase,
    ): array {
        $errors = [];
        $relationshipApplied = $applied[LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION];
        $monitoringApplied = $applied[LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION];

        if ($monitoringApplied && ! $relationshipApplied) {
            $errors[] = 'goal_migration_order_invalid';
        }

        if ($phase === 'postflight' && (! $relationshipApplied || ! $monitoringApplied)) {
            $errors[] = 'goal_migrations_not_both_applied';
        }

        $errors = array_merge(
            $errors,
            $this->relationshipSchemaErrors($connection, $database, $relationshipApplied),
            $this->monitoringSchemaErrors($connection, $database, $monitoringApplied),
            $this->targetEngineErrors($connection, $database),
        );

        if ($phase === 'preflight' && ! $monitoringApplied) {
            $errors = array_merge($errors, $this->monitoringEvidenceIntegrityErrors($connection));
        }

        return $errors;
    }

    /** @return list<string> */
    private function relationshipSchemaErrors(Connection $connection, string $database, bool $applied): array
    {
        if (! $connection->getSchemaBuilder()->hasTable('device_relationships')) {
            return $applied ? ['relationship_table_missing_after_migration'] : [];
        }

        $columns = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'device_relationships')
            ->pluck('COLUMN_NAME')
            ->map(fn (mixed $column): string => (string) $column)
            ->all();
        $newColumns = [
            'created_by_user_id',
            'unlinked_at',
            'unlinked_by_user_id',
            'unlink_reason',
            'active_relationship_guard',
        ];
        $indexes = $connection->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'device_relationships')
            ->pluck('INDEX_NAME')
            ->map(fn (mixed $index): string => (string) $index)
            ->unique()
            ->all();
        $legacyIndexMatches = $this->indexMatches(
            $connection,
            $database,
            'device_relationships',
            'dev_rel_pair_type_unique',
            LifecycleTriggerDeploymentGuard::LEGACY_RELATIONSHIP_INDEX_COLUMNS,
        );
        $activeIndexMatches = $this->indexMatches(
            $connection,
            $database,
            'device_relationships',
            'dev_rel_active_pair_type_unique',
            LifecycleTriggerDeploymentGuard::ACTIVE_RELATIONSHIP_INDEX_COLUMNS,
        );

        if (! $applied) {
            if (array_intersect($newColumns, $columns) !== []
                || in_array('dev_rel_active_pair_type_unique', $indexes, true)) {
                return ['relationship_migration_partial_schema'];
            }

            return $legacyIndexMatches
                ? []
                : ['relationship_migration_precondition_missing'];
        }

        $errors = [];
        if (array_diff($newColumns, $columns) !== []) {
            $errors[] = 'relationship_migration_columns_missing';
        }
        if (! $activeIndexMatches) {
            $errors[] = 'relationship_migration_unique_guard_missing';
        }
        if (in_array('dev_rel_pair_type_unique', $indexes, true)) {
            $errors[] = 'relationship_migration_obsolete_unique_present';
        }

        $activeGuard = $connection->selectOne(
            'SELECT COLUMN_TYPE AS column_type, EXTRA AS extra, '
            .'GENERATION_EXPRESSION AS generation_expression '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, 'device_relationships', 'active_relationship_guard'],
        );
        $generationExpression = $activeGuard === null
            ? ''
            : strtolower((string) preg_replace(
                '/[\s`()]+/',
                '',
                (string) $activeGuard->generation_expression,
            ));
        if ($activeGuard === null
            || strtolower((string) $activeGuard->column_type) !== 'tinyint unsigned'
            || stripos((string) $activeGuard->extra, 'VIRTUAL GENERATED') === false
            || $generationExpression !== LifecycleTriggerDeploymentGuard::ACTIVE_RELATIONSHIP_GENERATION_EXPRESSION) {
            $errors[] = 'relationship_migration_generated_guard_mismatch';
        }

        $foreignKeys = $connection->select(
            'SELECT kcu.COLUMN_NAME AS column_name, kcu.REFERENCED_TABLE_NAME AS referenced_table, '
            .'rc.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE kcu '
            .'JOIN information_schema.REFERENTIAL_CONSTRAINTS rc '
            .'ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA '
            .'AND rc.TABLE_NAME = kcu.TABLE_NAME AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
            .'WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, 'device_relationships'],
        );
        $expected = [
            'parent_device_id' => ['devices', 'RESTRICT'],
            'child_device_id' => ['devices', 'RESTRICT'],
            'created_by_user_id' => ['users', 'RESTRICT'],
            'unlinked_by_user_id' => ['users', 'RESTRICT'],
        ];
        $actual = [];
        foreach ($foreignKeys as $foreignKey) {
            $actual[(string) $foreignKey->column_name] = [
                (string) $foreignKey->referenced_table,
                strtoupper((string) $foreignKey->delete_rule),
            ];
        }
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            $errors[] = 'relationship_migration_foreign_keys_mismatch';
        }

        return $errors;
    }

    /**
     * @param  list<string>  $expectedColumns
     */
    private function indexMatches(
        Connection $connection,
        string $database,
        string $table,
        string $index,
        array $expectedColumns,
    ): bool {
        $rows = $connection->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->orderBy('SEQ_IN_INDEX')
            ->get(['COLUMN_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX']);

        if ($rows->count() !== count($expectedColumns)
            || $rows->contains(fn (object $row): bool => (int) $row->NON_UNIQUE !== 0)) {
            return false;
        }

        return $rows->values()->every(
            fn (object $row, int $offset): bool => (int) $row->SEQ_IN_INDEX === $offset + 1
                && (string) $row->COLUMN_NAME === $expectedColumns[$offset],
        );
    }

    /** @return list<string> */
    private function monitoringSchemaErrors(Connection $connection, string $database, bool $applied): array
    {
        $eventTables = [
            'monitoring_configuration_snapshot_storage_events',
            'monitoring_metric_series_pointer_events',
        ];
        $present = array_values(array_filter(
            $eventTables,
            fn (string $table): bool => $connection->getSchemaBuilder()->hasTable($table),
        ));

        if (! $applied) {
            return $present === [] ? [] : ['monitoring_migration_partial_schema'];
        }

        if (count($present) !== count($eventTables)) {
            return ['monitoring_migration_event_tables_missing'];
        }

        $expected = [
            'monitoring_configuration_snapshot_storage_events' => [
                'foreign_key_column' => 'snapshot_id',
                'referenced_table' => 'monitoring_configuration_snapshots',
                'index' => 'monitoring_snapshot_storage_event_time_idx',
                'columns' => [
                    'id',
                    'snapshot_id',
                    'from_storage_state',
                    'to_storage_state',
                    'from_payload_deleted_at',
                    'to_payload_deleted_at',
                    'transition_kind',
                    'occurred_at',
                ],
            ],
            'monitoring_metric_series_pointer_events' => [
                'foreign_key_column' => 'series_id',
                'referenced_table' => 'monitoring_metric_series',
                'index' => 'monitoring_series_pointer_event_time_idx',
                'columns' => [
                    'id',
                    'series_id',
                    'from_first_point_at',
                    'to_first_point_at',
                    'from_last_point_at',
                    'to_last_point_at',
                    'transition_kind',
                    'occurred_at',
                ],
            ],
        ];

        foreach ($expected as $table => $schema) {
            $columns = $connection->table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->pluck('COLUMN_NAME')
                ->map(fn (mixed $column): string => (string) $column)
                ->all();
            $indexes = $connection->table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->pluck('INDEX_NAME')
                ->map(fn (mixed $index): string => (string) $index)
                ->unique()
                ->all();
            $foreignKeys = $connection->table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->get(['COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'CONSTRAINT_NAME']);

            if (array_diff($schema['columns'], $columns) !== []
                || ! in_array($schema['index'], $indexes, true)
                || $foreignKeys->count() !== 1) {
                return ['monitoring_migration_event_table_integrity_mismatch'];
            }

            $foreignKey = $foreignKeys->first();
            $deleteRule = $connection->table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', (string) $foreignKey->CONSTRAINT_NAME)
                ->value('DELETE_RULE');
            if ((string) $foreignKey->COLUMN_NAME !== $schema['foreign_key_column']
                || (string) $foreignKey->REFERENCED_TABLE_NAME !== $schema['referenced_table']
                || strtoupper((string) $deleteRule) !== 'RESTRICT') {
                return ['monitoring_migration_event_table_integrity_mismatch'];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function targetEngineErrors(Connection $connection, string $database): array
    {
        $tables = [
            'device_relationships',
            'monitoring_configuration_snapshots',
            'monitoring_retention_tombstones',
            'monitoring_metric_series',
            'monitoring_configuration_snapshot_storage_events',
            'monitoring_metric_series_pointer_events',
        ];
        $invalid = $connection->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $database)
            ->whereIn('TABLE_NAME', $tables)
            ->whereRaw('UPPER(ENGINE) <> ?', ['INNODB'])
            ->exists();

        return $invalid ? ['goal_table_engine_not_innodb'] : [];
    }

    /** @return list<string> */
    private function monitoringEvidenceIntegrityErrors(Connection $connection): array
    {
        $requiredTables = [
            'monitoring_configuration_snapshots',
            'monitoring_metric_series',
            'monitoring_retention_tombstones',
        ];
        foreach ($requiredTables as $table) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return [];
            }
        }

        if ($connection->table('monitoring_configuration_snapshots')
            ->where(function ($query): void {
                $query->whereNotIn('storage_state', [
                    'available',
                    'integrity_failed',
                    'missing',
                    'unavailable',
                    'deleted',
                ])->orWhereRaw(
                    '(storage_state = ? AND payload_deleted_at IS NULL) OR (storage_state <> ? AND payload_deleted_at IS NOT NULL)',
                    ['deleted', 'deleted'],
                );
            })->exists()) {
            return ['monitoring_snapshot_lifecycle_inconsistent'];
        }

        if ($connection->table('monitoring_metric_series')
            ->whereRaw(
                '(first_point_at IS NULL AND last_point_at IS NOT NULL)'
                .' OR (first_point_at IS NOT NULL AND last_point_at IS NULL)'
                .' OR first_point_at > last_point_at',
            )->exists()) {
            return ['monitoring_series_pointer_lifecycle_inconsistent'];
        }

        $invalidTombstone = $connection->table('monitoring_retention_tombstones as tombstone')
            ->leftJoin('monitoring_metric_series as series', 'series.id', '=', 'tombstone.series_id')
            ->leftJoin(
                'monitoring_configuration_snapshots as snapshot',
                'snapshot.id',
                '=',
                'tombstone.snapshot_id',
            )
            ->where(function ($query): void {
                $query->whereRaw('(tombstone.series_id IS NULL) = (tombstone.snapshot_id IS NULL)')
                    ->orWhereRaw('tombstone.period_start > tombstone.period_end')
                    ->orWhere(function ($series): void {
                        $series->whereNotNull('tombstone.series_id')
                            ->where(function ($mismatch): void {
                                $mismatch->whereNull('series.id')
                                    ->orWhereColumn('series.site_id', '!=', 'tombstone.site_id')
                                    ->orWhereColumn('series.device_id', '!=', 'tombstone.device_id')
                                    ->orWhereRaw('NOT (series.monitor_id <=> tombstone.monitor_id)')
                                    ->orWhereColumn('series.data_class', '!=', 'tombstone.data_class')
                                    ->orWhereColumn('series.retention_tier', '!=', 'tombstone.retention_tier');
                            });
                    })
                    ->orWhere(function ($snapshot): void {
                        $snapshot->whereNotNull('tombstone.snapshot_id')
                            ->where(function ($mismatch): void {
                                $mismatch->whereNull('snapshot.id')
                                    ->orWhereColumn('snapshot.site_id', '!=', 'tombstone.site_id')
                                    ->orWhereColumn('snapshot.device_id', '!=', 'tombstone.device_id')
                                    ->orWhereNotNull('tombstone.monitor_id')
                                    ->orWhere('tombstone.data_class', '!=', 'configuration')
                                    ->orWhere('tombstone.retention_tier', '!=', 'configuration')
                                    ->orWhereRaw('NOT (tombstone.period_start <=> snapshot.captured_at)')
                                    ->orWhereRaw('NOT (tombstone.period_end <=> snapshot.captured_at)');
                            });
                    });
            })->exists();

        return $invalidTombstone ? ['monitoring_tombstone_lineage_inconsistent'] : [];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, bool>  $applied
     */
    private function finish(
        string $phase,
        array $errors,
        array $applied = [],
        ?bool $binaryLogging = null,
        ?bool $trustFunctionCreators = null,
        ?int $observedTriggers = null,
    ): int {
        $relationshipState = array_key_exists(LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION, $applied)
            ? ($applied[LifecycleTriggerDeploymentGuard::RELATIONSHIP_MIGRATION] ? 'applied' : 'pending')
            : 'unknown';
        $monitoringState = array_key_exists(LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION, $applied)
            ? ($applied[LifecycleTriggerDeploymentGuard::MONITORING_MIGRATION] ? 'applied' : 'pending')
            : 'unknown';
        $report = [
            'status' => $errors === [] ? 'verified' : 'failed',
            'phase' => $phase,
            'checked_at_utc' => now()->utc()->toIso8601ZuluString(),
            'binary_logging' => $binaryLogging === null
                ? 'unknown'
                : ($binaryLogging ? 'enabled' : 'disabled'),
            'binary_log_trigger_trust' => $binaryLogging === null
                ? 'unknown'
                : ($binaryLogging
                    ? ($trustFunctionCreators ? 'required_and_enabled' : 'required_and_disabled')
                    : 'not_required'),
            'relationship_migration' => $relationshipState,
            'monitoring_migration' => $monitoringState,
            'expected_trigger_count' => count(LifecycleTriggerDeploymentGuard::ROSTER),
            'observed_trigger_count' => $observedTriggers,
            'errors' => $errors,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } elseif ($errors === []) {
            $this->info('Lifecycle trigger '.$phase.' verified.');
        } else {
            $this->error('Lifecycle trigger '.$phase.' failed: '.implode(', ', $errors));
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
