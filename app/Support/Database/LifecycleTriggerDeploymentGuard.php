<?php

namespace App\Support\Database;

final class LifecycleTriggerDeploymentGuard
{
    public const RELATIONSHIP_MIGRATION = '2026_08_06_000041_retain_device_relationship_history';

    public const MONITORING_MIGRATION = '2026_08_06_000047_enforce_monitoring_evidence_lifecycle';

    /** @var list<string> */
    public const LEGACY_RELATIONSHIP_INDEX_COLUMNS = [
        'parent_device_id',
        'child_device_id',
        'relationship_type',
    ];

    /** @var list<string> */
    public const ACTIVE_RELATIONSHIP_INDEX_COLUMNS = [
        'parent_device_id',
        'child_device_id',
        'relationship_type',
        'active_relationship_guard',
    ];

    public const ACTIVE_RELATIONSHIP_GENERATION_EXPRESSION = 'casewhenunlinked_atisnullthen1elsenullend';

    /** @var list<string> */
    public const REQUIRED_SCHEMA_PRIVILEGES = [
        'ALTER',
        'CREATE',
        'INDEX',
        'INSERT',
        'REFERENCES',
        'SELECT',
        'TRIGGER',
    ];

    /** @var list<string> */
    public const PROHIBITED_GLOBAL_PRIVILEGES = [
        'BINLOG_ADMIN',
        'SET_USER_ID',
        'SUPER',
        'SYSTEM_VARIABLES_ADMIN',
    ];

    /**
     * The exact target-table trigger surface for these two migrations.
     *
     * @var array<string, array{migration: string, table: string, timing: string, event: string}>
     */
    public const ROSTER = [
        'device_relationships_before_insert_guard' => [
            'migration' => self::RELATIONSHIP_MIGRATION,
            'table' => 'device_relationships',
            'timing' => 'BEFORE',
            'event' => 'INSERT',
        ],
        'device_relationships_before_update_guard' => [
            'migration' => self::RELATIONSHIP_MIGRATION,
            'table' => 'device_relationships',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
        ],
        'device_relationships_before_delete_guard' => [
            'migration' => self::RELATIONSHIP_MIGRATION,
            'table' => 'device_relationships',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
        ],
        'monitoring_snapshots_before_update_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_configuration_snapshots',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
        ],
        'monitoring_snapshots_after_update_audit' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_configuration_snapshots',
            'timing' => 'AFTER',
            'event' => 'UPDATE',
        ],
        'monitoring_snapshots_before_delete_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_configuration_snapshots',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
        ],
        'monitoring_tombstones_before_insert_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_retention_tombstones',
            'timing' => 'BEFORE',
            'event' => 'INSERT',
        ],
        'monitoring_tombstones_before_update_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_retention_tombstones',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
        ],
        'monitoring_tombstones_before_delete_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_retention_tombstones',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
        ],
        'monitoring_series_before_update_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_metric_series',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
        ],
        'monitoring_series_after_update_audit' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_metric_series',
            'timing' => 'AFTER',
            'event' => 'UPDATE',
        ],
        'monitoring_series_before_delete_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_metric_series',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
        ],
        'monitoring_snapshot_events_update_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_configuration_snapshot_storage_events',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
        ],
        'monitoring_snapshot_events_delete_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_configuration_snapshot_storage_events',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
        ],
        'monitoring_series_events_update_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_metric_series_pointer_events',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
        ],
        'monitoring_series_events_delete_guard' => [
            'migration' => self::MONITORING_MIGRATION,
            'table' => 'monitoring_metric_series_pointer_events',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
        ],
    ];

    /** @return list<string> */
    public function targetTables(): array
    {
        return array_values(array_unique(array_column(self::ROSTER, 'table')));
    }

    public function binaryLogAllowsTriggerCreation(bool $binaryLoggingEnabled, bool $trustFunctionCreators): bool
    {
        return ! $binaryLoggingEnabled || $trustFunctionCreators;
    }

    public function supportsMySqlVersion(string $version, string $comment): bool
    {
        if (stripos($version, 'mariadb') !== false || stripos($comment, 'mariadb') !== false) {
            return false;
        }

        if (! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            return false;
        }

        return version_compare($matches[1], '8.0.19', '>=')
            && version_compare($matches[1], '9.0.0', '<');
    }

    /**
     * @param  list<string>  $grantStatements
     * @return list<string>
     */
    public function schemaPrivileges(array $grantStatements, string $database): array
    {
        $privileges = [];
        $revoked = [];

        foreach ($grantStatements as $statement) {
            $parsed = $this->parsePrivilegeStatement($statement);
            if ($parsed === null || ! $this->scopeAppliesToDatabase($parsed['scope'], $database)) {
                continue;
            }

            $declared = $parsed['privileges'];
            if ($declared === 'ALL PRIVILEGES') {
                if ($parsed['verb'] === 'GRANT') {
                    $privileges = array_merge($privileges, self::REQUIRED_SCHEMA_PRIVILEGES);
                } else {
                    $revoked = array_merge($revoked, self::REQUIRED_SCHEMA_PRIVILEGES);
                }

                continue;
            }

            foreach (explode(',', $declared) as $privilege) {
                if ($parsed['verb'] === 'GRANT') {
                    $privileges[] = trim($privilege);
                } else {
                    $revoked[] = trim($privilege);
                }
            }
        }

        return array_values(array_diff(array_unique($privileges), array_unique($revoked)));
    }

    /**
     * @param  list<string>  $grantStatements
     * @return list<string>
     */
    public function prohibitedGlobalPrivileges(array $grantStatements): array
    {
        $prohibited = [];

        foreach ($grantStatements as $statement) {
            $parsed = $this->parsePrivilegeStatement($statement);
            if ($parsed === null || $parsed['verb'] !== 'GRANT' || $parsed['scope'] !== '*.*') {
                continue;
            }

            $declared = $parsed['privileges'];
            if ($declared === 'ALL PRIVILEGES') {
                $prohibited[] = 'GLOBAL_ALL';

                continue;
            }

            $prohibited = array_merge(
                $prohibited,
                array_intersect(
                    array_map('trim', explode(',', $declared)),
                    self::PROHIBITED_GLOBAL_PRIVILEGES,
                ),
            );
        }

        return array_values(array_unique($prohibited));
    }

    /**
     * @param  array<string, string>  $migrationSources
     * @return array<string, array{migration: string, table: string, timing: string, event: string, action_hash: string}>
     */
    public function expectedRoster(array $migrationSources): array
    {
        return $this->sourceRosterResult($migrationSources)['roster'];
    }

    /**
     * @param  array<string, string>  $migrationSources
     * @return array{
     *     roster: array<string, array{migration: string, table: string, timing: string, event: string, action_hash: string}>,
     *     raw_count: int,
     *     duplicate_names: list<string>
     * }
     */
    public function sourceRosterResult(array $migrationSources): array
    {
        $parsed = [];
        $rawCount = 0;
        $duplicateNames = [];

        foreach ($migrationSources as $migration => $source) {
            $rawCount += preg_match_all('/\bCREATE\s+TRIGGER\b/i', $source);
            preg_match_all(
                '/CREATE TRIGGER\s+([a-z0-9_]+)\s+(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+([a-z0-9_]+)\s+FOR EACH ROW\s+(.*?)\R\s+SQL\);/si',
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                if (isset($parsed[$name])) {
                    $duplicateNames[] = $name;
                }
                $parsed[$name] = [
                    'migration' => $migration,
                    'table' => strtolower($match[4]),
                    'timing' => strtoupper($match[2]),
                    'event' => strtoupper($match[3]),
                    'action_hash' => hash('sha256', $this->normaliseAction($match[5])),
                ];
            }
        }

        ksort($parsed);

        return [
            'roster' => $parsed,
            'raw_count' => $rawCount,
            'duplicate_names' => array_values(array_unique($duplicateNames)),
        ];
    }

    /**
     * @param  array<string, array{migration: string, table: string, timing: string, event: string, action_hash: string}>  $sourceRoster
     * @return list<string>
     */
    public function sourceRosterErrors(
        array $sourceRoster,
        ?int $rawCount = null,
        array $duplicateNames = [],
    ): array {
        $errors = [];
        $expectedNames = array_keys(self::ROSTER);
        $actualNames = array_keys($sourceRoster);

        sort($expectedNames);
        sort($actualNames);

        if ($rawCount !== null && $rawCount !== count(self::ROSTER)) {
            $errors[] = 'source_trigger_raw_count_mismatch';
        }
        if ($duplicateNames !== []) {
            $errors[] = 'source_trigger_duplicate_name';
        }
        if ($expectedNames !== $actualNames) {
            $errors[] = 'source_trigger_roster_mismatch';
        }

        foreach (self::ROSTER as $name => $expected) {
            if (! isset($sourceRoster[$name])) {
                continue;
            }
            foreach (['migration', 'table', 'timing', 'event'] as $field) {
                if (($sourceRoster[$name][$field] ?? null) !== $expected[$field]) {
                    $errors[] = "source_trigger_{$name}_{$field}_mismatch";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<array{name: string, table: string, timing: string, event: string, action: string, definer: string}>  $liveRows
     * @param  array<string, array{migration: string, table: string, timing: string, event: string, action_hash: string}>  $sourceRoster
     * @param  list<string>  $requiredMigrations
     * @return list<string>
     */
    public function liveRosterErrors(
        array $liveRows,
        array $sourceRoster,
        array $requiredMigrations,
        string $currentUser,
    ): array {
        $errors = [];
        $required = array_filter(
            $sourceRoster,
            fn (array $trigger): bool => in_array($trigger['migration'], $requiredMigrations, true),
        );
        $live = [];

        foreach ($liveRows as $row) {
            $name = strtolower($row['name']);
            if (isset($live[$name])) {
                $errors[] = "live_trigger_{$name}_duplicate";
            }
            $live[$name] = $row;
        }

        $expectedNames = array_keys($required);
        $liveNames = array_keys($live);
        sort($expectedNames);
        sort($liveNames);

        if ($expectedNames !== $liveNames) {
            $errors[] = 'live_trigger_roster_mismatch';
        }

        foreach ($required as $name => $expected) {
            $row = $live[$name] ?? null;
            if ($row === null) {
                continue;
            }

            foreach (['table', 'timing', 'event'] as $field) {
                if (strtoupper($row[$field]) !== strtoupper($expected[$field])) {
                    $errors[] = "live_trigger_{$name}_{$field}_mismatch";
                }
            }

            if (! hash_equals($expected['action_hash'], hash('sha256', $this->normaliseAction($row['action'])))) {
                $errors[] = "live_trigger_{$name}_body_mismatch";
            }

            if (! hash_equals($currentUser, $row['definer'])) {
                $errors[] = "live_trigger_{$name}_definer_mismatch";
            }
        }

        return array_values(array_unique($errors));
    }

    private function normaliseAction(string $action): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $action));
    }

    /** @return array{verb: string, privileges: string, scope: string}|null */
    private function parsePrivilegeStatement(string $statement): ?array
    {
        if (! preg_match(
            '/^(GRANT|REVOKE)\s+(.+?)\s+ON\s+(.+?)\s+(?:TO|FROM)\s+/i',
            trim($statement),
            $matches,
        )) {
            return null;
        }

        return [
            'verb' => strtoupper($matches[1]),
            'privileges' => strtoupper(trim($matches[2])),
            'scope' => trim($matches[3]),
        ];
    }

    private function scopeAppliesToDatabase(string $scope, string $database): bool
    {
        if ($scope === '*.*') {
            return true;
        }

        if (! preg_match('/^`((?:``|\\\\.|[^`])*)`\.\*$/s', $scope, $matches)) {
            return false;
        }

        $identifier = str_replace('``', '`', $matches[1]);
        $identifier = (string) preg_replace('/\\\\(.)/s', '$1', $identifier);

        return hash_equals($database, $identifier);
    }
}
