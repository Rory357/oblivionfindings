<?php

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AuditItSecuritySingleTenantData extends Command
{
    protected $signature = 'it-security:audit-single-tenant-data
        {--connection= : Database connection to audit}
        {--format=markdown : Output format: markdown or json}';

    protected $description = 'Read-only, redacted collision and provenance audit for IT, Security & Devices, and Monitoring single-tenant remediation.';

    /** @var list<array{key: string, table: string, columns: list<string>}> */
    private const GLOBAL_KEYS = [
        ['key' => 'ticket_reference', 'table' => 'it_tickets', 'columns' => ['reference']],
        ['key' => 'sla_priority', 'table' => 'it_sla_policies', 'columns' => ['priority']],
        ['key' => 'kb_slug', 'table' => 'it_kb_articles', 'columns' => ['slug']],
        ['key' => 'mailbox_provider', 'table' => 'it_mailbox_connections', 'columns' => ['provider']],
        ['key' => 'team_name', 'table' => 'it_teams', 'columns' => ['name']],
        ['key' => 'queue_key', 'table' => 'it_queues', 'columns' => ['key']],
        ['key' => 'service_key', 'table' => 'it_services', 'columns' => ['key']],
        ['key' => 'catalogue_slug', 'table' => 'it_catalog_items', 'columns' => ['slug']],
        ['key' => 'catalogue_requester_idempotency', 'table' => 'it_catalog_submissions', 'columns' => ['requester_user_id', 'idempotency_key']],
        ['key' => 'provisioning_source_event', 'table' => 'it_provisioning_workflows', 'columns' => ['source_event_key']],
        ['key' => 'collector_uuid', 'table' => 'monitoring_collectors', 'columns' => ['collector_uuid']],
        ['key' => 'monitoring_profile_name', 'table' => 'monitoring_profiles', 'columns' => ['name']],
        ['key' => 'device_group_name', 'table' => 'device_groups', 'columns' => ['name']],
        ['key' => 'integration_provider', 'table' => 'integrations', 'columns' => ['provider']],
        ['key' => 'integration_provider_event', 'table' => 'integration_events', 'columns' => ['provider', 'source_event_id']],
        ['key' => 'queclink_preset_slug', 'table' => 'queclink_presets', 'columns' => ['slug']],
    ];

    private const MAX_FINGERPRINTS_PER_CHECK = 25;

    /** @var list<array{table: string, column: string}> */
    private const BOUNDARY_ID_SOURCES = [
        ['table' => 'sites', 'column' => 'tenant_id'],
        ['table' => 'sites', 'column' => 'organization_id'],
        ['table' => 'users', 'column' => 'tenant_id'],
        ['table' => 'users', 'column' => 'organization_id'],
        ['table' => 'clients', 'column' => 'tenant_id'],
        ['table' => 'clients', 'column' => 'organization_id'],
        ['table' => 'hr_employee_profiles', 'column' => 'tenant_id'],
        ['table' => 'hr_employee_profiles', 'column' => 'organization_id'],
        ['table' => 'assets', 'column' => 'tenant_id'],
        ['table' => 'assets', 'column' => 'organization_id'],
        ['table' => 'hr_assets', 'column' => 'tenant_id'],
        ['table' => 'hr_assets', 'column' => 'organization_id'],
        ['table' => 'site_rooms', 'column' => 'tenant_id'],
        ['table' => 'location_hardware', 'column' => 'tenant_id'],
    ];

    /** @var list<string> */
    private const DEVICE_ASSIGNMENT_TYPES = ['site', 'room', 'client', 'staff', 'vehicle'];

    /** @var list<array{key: string, table: string, types: list<string>}> */
    private const IT_TICKET_LINK_TARGETS = [
        ['key' => 'security_device', 'table' => 'devices', 'types' => ['security_device', 'App\\Domain\\SecurityDevices\\Models\\Device']],
        ['key' => 'control_room_alert', 'table' => 'control_room_alerts', 'types' => ['control_room_alert', 'App\\Models\\ControlRoomAlert']],
        ['key' => 'it_ticket', 'table' => 'it_tickets', 'types' => ['it_ticket', 'App\\Models\\ItTicket']],
        ['key' => 'it_service', 'table' => 'it_services', 'types' => ['it_service', 'App\\Models\\ItService']],
        ['key' => 'site', 'table' => 'sites', 'types' => ['site', 'App\\Models\\Site']],
    ];

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['json', 'markdown'], true)) {
            $this->error('Audit could not start safely.');

            return self::FAILURE;
        }

        try {
            $connection = DB::connection($this->option('connection') ?: null);
            $report = $this->readOnly($connection, fn (): array => $this->buildReport($connection));
        } catch (Throwable) {
            $this->error('Audit could not start safely.');

            return self::FAILURE;
        }

        $this->line($format === 'json'
            ? json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : $this->toMarkdown($report));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildReport(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $targetTables = $this->targetTables($schema);
        $legacy = $this->legacyIds($connection, $schema, $targetTables);
        $globalKeys = array_map(
            fn (array $definition): array => $this->globalKeyCheck($connection, $schema, $definition),
            self::GLOBAL_KEYS,
        );
        $ticketReferenceCheck = collect($globalKeys)->firstWhere('key', 'ticket_reference');
        $inboundEmailAmbiguity = [
            'status' => ($ticketReferenceCheck['status'] ?? null) === 'not_available'
                ? 'not_available'
                : (((int) ($ticketReferenceCheck['duplicate_groups'] ?? 0)) > 0 ? 'ambiguous' : 'clear'),
            'ambiguous_reference_groups' => (int) ($ticketReferenceCheck['duplicate_groups'] ?? 0),
            'ambiguous_ticket_rows' => (int) ($ticketReferenceCheck['duplicate_rows'] ?? 0),
        ];
        $provenance = $this->provenanceChecks($connection, $schema);
        $orphans = $this->orphanChecks($connection, $schema);
        $nullSiteTickets = $this->nullSiteTickets($connection, $schema);
        $deviceAssignments = $this->deviceAssignmentSummary($connection, $schema);
        $tenantIndexes = $this->tenantLeadingIndexes($schema, $targetTables);

        return [
            'contract_version' => 1,
            'scope' => 'single_application',
            'read_only' => true,
            'redaction' => 'keyed_sha256_fingerprints_only',
            'meaning' => 'Task 1 provides no-regression evidence and does not prove single-tenant remediation is complete.',
            'summary' => [
                'legacy_id_values' => count($legacy['values']),
                'legacy_null_rows' => $legacy['null_rows'],
                'unavailable_legacy_id_sources' => collect($legacy['sources'])->where('status', 'not_available')->count(),
                'global_key_collision_groups' => collect($globalKeys)->sum('duplicate_groups'),
                'global_key_collision_rows' => collect($globalKeys)->sum('duplicate_rows'),
                'provenance_findings' => collect($provenance)->sum('count'),
                'orphan_findings' => collect($orphans)->sum('count'),
                'null_site_tickets' => $nullSiteTickets['total'],
                'unassigned_devices' => $deviceAssignments['unassigned_devices'],
                'ambiguously_assigned_devices' => $deviceAssignments['ambiguously_assigned_devices'],
                'tenant_leading_indexes' => count($tenantIndexes),
                'unavailable_checks' => collect([
                    ...$globalKeys,
                    ...$provenance,
                    ...$orphans,
                    $inboundEmailAmbiguity,
                    $nullSiteTickets,
                    $deviceAssignments,
                ])->where('status', 'not_available')->count()
                    + ($nullSiteTickets['evidence_field_status'] === 'not_available' ? 1 : 0),
            ],
            'legacy_ids' => $legacy['values'],
            'legacy_id_sources' => $legacy['sources'],
            'global_key_checks' => $globalKeys,
            'inbound_email_ambiguity' => $inboundEmailAmbiguity,
            'provenance_checks' => $provenance,
            'orphan_checks' => $orphans,
            'null_site_tickets' => $nullSiteTickets,
            'device_assignments' => $deviceAssignments,
            'tenant_leading_indexes' => $tenantIndexes,
        ];
    }

    /**
     * Install a query guard before any audit query. When this command owns the
     * transaction it also asks the database for a read-only transaction. Test
     * suites may already have an outer transaction; the pre-execution guard is
     * the fail-closed backstop in that case.
     */
    private function readOnly(Connection $connection, Closure $callback): mixed
    {
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver.');
        }

        $ownsTransaction = $connection->transactionLevel() === 0;
        $sqliteQueryOnly = false;

        if ($ownsTransaction && in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION READ ONLY');
        }

        if ($ownsTransaction && $driver === 'sqlite') {
            $connection->statement('PRAGMA query_only = ON');
            $sqliteQueryOnly = true;
        }

        if ($ownsTransaction) {
            $connection->beginTransaction();

            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
            }
        }

        $guardActive = true;
        $connection->beforeExecuting(function (string $query) use (&$guardActive): void {
            if ($guardActive && ! $this->isAllowedAuditQuery($query)) {
                throw new RuntimeException('The audit attempted a non-read query.');
            }
        });

        try {
            return $callback();
        } finally {
            $guardActive = false;

            if ($ownsTransaction && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($sqliteQueryOnly) {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }
    }

    private function isAllowedAuditQuery(string $query): bool
    {
        $query = trim($query);
        if (str_ends_with($query, ';')) {
            $query = rtrim(substr($query, 0, -1));
        }

        if ($query === '' || str_contains($query, ';')) {
            return false;
        }

        if (preg_match('/^(?:select|show|describe)\b[\s\S]*$/i', $query) === 1) {
            return true;
        }

        if (preg_match('/^pragma\s+database_list$/i', $query) === 1) {
            return true;
        }

        return preg_match('/^pragma\s+(?:(?:main|temp)\.)?(?:table_info|table_xinfo|index_list|index_info|index_xinfo|foreign_key_list)\s*\(\s*(?:"[^"]+"|\'[^\']+\'|`[^`]+`|\[[^\]]+\]|[A-Za-z0-9_.-]+)\s*\)$/i', $query) === 1;
    }

    /** @return list<string> */
    private function targetTables(SchemaBuilder $schema): array
    {
        return collect($schema->getTables())
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name)
                && preg_match('/^(it_|monitor|devices$|device_|integration|queclink_|site_rooms$|location_hardware$)/', $name) === 1)
            ->sort()
            ->values()
            ->all();
    }

    /** @param list<string> $tables
     *  @return array{
     *      values: list<array{fingerprint: string, row_count: int, table_count: int, source_count: int}>,
     *      sources: list<array{source: string, status: string, row_count: int, null_rows: int, distinct_values: int}>,
     *      null_rows: int
     *  }
     */
    private function legacyIds(Connection $connection, SchemaBuilder $schema, array $tables): array
    {
        $values = [];
        $nullRows = 0;
        $sourceReports = [];
        $sources = collect($tables)
            ->map(fn (string $table): array => ['table' => $table, 'column' => 'tenant_id'])
            ->concat(self::BOUNDARY_ID_SOURCES)
            ->unique(fn (array $source): string => $source['table'].'.'.$source['column'])
            ->sortBy(fn (array $source): string => $source['table'].'.'.$source['column'])
            ->values();

        foreach ($sources as $source) {
            $table = $source['table'];
            $column = $source['column'];
            $sourceName = $table.'.'.$column;

            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) {
                $sourceReports[] = [
                    'source' => $sourceName,
                    'status' => 'not_available',
                    'row_count' => 0,
                    'null_rows' => 0,
                    'distinct_values' => 0,
                ];

                continue;
            }

            $sourceNullRows = $connection->table($table)->whereNull($column)->count();
            $sourceRowCount = $connection->table($table)->whereNotNull($column)->count();
            $sourceDistinctValues = 0;
            $nullRows += $sourceNullRows;

            foreach ($connection->table($table)
                ->select($column)
                ->selectRaw('COUNT(*) AS row_count')
                ->whereNotNull($column)
                ->groupBy($column)
                ->get() as $row) {
                $sourceDistinctValues++;
                $fingerprint = $this->fingerprint('legacy_id', [(string) $row->{$column}]);
                $values[$fingerprint] ??= [
                    'fingerprint' => $fingerprint,
                    'row_count' => 0,
                    'source_count' => 0,
                    '_tables' => [],
                ];
                $values[$fingerprint]['row_count'] += (int) $row->row_count;
                $values[$fingerprint]['source_count']++;
                $values[$fingerprint]['_tables'][$table] = true;
            }

            $sourceReports[] = [
                'source' => $sourceName,
                'status' => 'audited',
                'row_count' => $sourceRowCount,
                'null_rows' => $sourceNullRows,
                'distinct_values' => $sourceDistinctValues,
            ];
        }

        ksort($values, SORT_STRING);

        $safeValues = collect($values)->map(function (array $value): array {
            $value['table_count'] = count($value['_tables']);
            unset($value['_tables']);

            return $value;
        })->values()->all();

        return ['values' => $safeValues, 'sources' => $sourceReports, 'null_rows' => $nullRows];
    }

    /** @param array{key: string, table: string, columns: list<string>} $definition
     * @return array<string, mixed>
     */
    private function globalKeyCheck(Connection $connection, SchemaBuilder $schema, array $definition): array
    {
        $required = [$definition['table'] => [...$definition['columns']]];
        $missing = $this->missingSchema($schema, $required);
        if ($missing !== []) {
            return [
                ...$definition,
                'status' => 'not_available',
                'duplicate_groups' => 0,
                'duplicate_rows' => 0,
                'findings' => [],
                'missing_schema' => $missing,
            ];
        }

        $hasLegacyId = $schema->hasColumn($definition['table'], 'tenant_id');
        $query = $connection->table($definition['table']);
        foreach ($definition['columns'] as $column) {
            $query->whereNotNull($column);
        }

        $selects = [...$definition['columns']];
        foreach ($selects as $column) {
            $query->addSelect($column);
        }
        $query->selectRaw('COUNT(*) AS row_count');
        if ($hasLegacyId) {
            $query->selectRaw('COUNT(DISTINCT tenant_id) AS legacy_id_count');
        }
        $query->groupBy($definition['columns'])->havingRaw('COUNT(*) > 1');
        foreach ($definition['columns'] as $column) {
            $query->orderBy($column);
        }

        $rows = $query->get();
        $findings = $rows->take(self::MAX_FINGERPRINTS_PER_CHECK)
            ->map(function (object $row) use ($definition, $hasLegacyId): array {
                $parts = array_map(fn (string $column): string => (string) $row->{$column}, $definition['columns']);

                return [
                    'fingerprint' => $this->fingerprint($definition['key'], $parts),
                    'row_count' => (int) $row->row_count,
                    'distinct_legacy_ids' => $hasLegacyId ? (int) $row->legacy_id_count : null,
                ];
            })->values()->all();
        $duplicateRows = $rows->sum(fn (object $row): int => (int) $row->row_count);

        return [
            ...$definition,
            'status' => $rows->isEmpty() ? 'clear' : 'collision',
            'duplicate_groups' => $rows->count(),
            'duplicate_rows' => $duplicateRows,
            'findings' => $findings,
            'truncated' => $rows->count() > self::MAX_FINGERPRINTS_PER_CHECK,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function provenanceChecks(Connection $connection, SchemaBuilder $schema): array
    {
        return [
            $this->queryCheck(
                $connection,
                $schema,
                'ticket_site_legacy_id_mismatch',
                ['it_tickets' => ['id', 'site_id', 'tenant_id'], 'sites' => ['id', 'tenant_id']],
                fn (): QueryBuilder => $connection->table('it_tickets as child')
                    ->join('sites as parent', 'parent.id', '=', 'child.site_id')
                    ->whereNotNull('child.tenant_id')->whereNotNull('parent.tenant_id')
                    ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')
                    ->select('child.id as record_id'),
            ),
            $this->queryCheck(
                $connection,
                $schema,
                'ticket_team_legacy_id_mismatch',
                ['it_tickets' => ['id', 'team_id', 'tenant_id'], 'it_teams' => ['id', 'tenant_id']],
                fn (): QueryBuilder => $connection->table('it_tickets as child')
                    ->join('it_teams as parent', 'parent.id', '=', 'child.team_id')
                    ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')
                    ->select('child.id as record_id'),
            ),
            $this->relationLegacyMismatch($connection, $schema, 'ticket_queue_legacy_id_mismatch', 'it_tickets', 'queue_id', 'it_queues'),
            $this->relationLegacyMismatch($connection, $schema, 'ticket_service_legacy_id_mismatch', 'it_tickets', 'it_service_id', 'it_services'),
            $this->relationLegacyMismatch($connection, $schema, 'monitor_device_legacy_id_mismatch', 'monitors', 'device_id', 'devices'),
            $this->relationLegacyMismatch($connection, $schema, 'monitor_profile_legacy_id_mismatch', 'monitors', 'profile_id', 'monitoring_profiles'),
            $this->relationLegacyMismatch($connection, $schema, 'monitor_collector_legacy_id_mismatch', 'monitors', 'collector_id', 'monitoring_collectors'),
            $this->relationLegacyMismatch($connection, $schema, 'collector_site_legacy_id_mismatch', 'monitoring_collectors', 'site_id', 'sites'),
            $this->relationLegacyMismatch($connection, $schema, 'provider_site_mapping_site_legacy_id_mismatch', 'integration_site_configs', 'site_id', 'sites'),
            $this->queryCheck(
                $connection,
                $schema,
                'device_active_site_assignment_conflict',
                ['device_assignments' => ['device_id', 'assignable_type', 'assignable_id', 'released_at']],
                fn (): QueryBuilder => $this->currentAssignmentQuery(
                    $connection->table('device_assignments'),
                    $schema,
                )
                    ->whereIn('assignable_type', ['site', 'App\\Models\\Site'])
                    ->groupBy('device_id')
                    ->havingRaw('COUNT(DISTINCT assignable_id) > 1')
                    ->select('device_id as record_id'),
            ),
            $this->queryCheck(
                $connection,
                $schema,
                'device_assignment_site_legacy_id_mismatch',
                ['device_assignments' => ['device_id', 'assignable_type', 'assignable_id', 'released_at'], 'devices' => ['id', 'tenant_id'], 'sites' => ['id', 'tenant_id']],
                fn (): QueryBuilder => $this->currentAssignmentQuery(
                    $connection->table('device_assignments as assignment'),
                    $schema,
                    'assignment',
                )
                    ->join('devices as device', 'device.id', '=', 'assignment.device_id')
                    ->join('sites as site', 'site.id', '=', 'assignment.assignable_id')
                    ->whereIn('assignment.assignable_type', ['site', 'App\\Models\\Site'])
                    ->whereNotNull('device.tenant_id')->whereNotNull('site.tenant_id')
                    ->whereColumn('device.tenant_id', '!=', 'site.tenant_id')
                    ->select('assignment.id as record_id'),
            ),
            $this->assignmentTargetSiteLegacyMismatch(
                $connection,
                $schema,
                'device_assignment_room_site_legacy_id_mismatch',
                'room',
                'site_rooms',
                'site_id',
            ),
            $this->assignmentTargetSiteLegacyMismatch(
                $connection,
                $schema,
                'device_assignment_client_site_legacy_id_mismatch',
                'client',
                'clients',
                'site_id',
            ),
            $this->assignmentTargetSiteLegacyMismatch(
                $connection,
                $schema,
                'device_assignment_vehicle_site_legacy_id_mismatch',
                'vehicle',
                'assets',
                'site_id',
            ),
            $this->assignmentTargetSiteLegacyMismatch(
                $connection,
                $schema,
                'device_assignment_vehicle_home_site_legacy_id_mismatch',
                'vehicle',
                'assets',
                'home_site_id',
            ),
            $this->queryCheck(
                $connection,
                $schema,
                'device_assignment_vehicle_client_site_legacy_id_mismatch',
                [
                    'device_assignments' => ['id', 'device_id', 'assignable_type', 'assignable_id', 'released_at'],
                    'devices' => ['id', 'tenant_id'],
                    'assets' => ['id', 'client_id'],
                    'clients' => ['id', 'site_id'],
                    'sites' => ['id', 'tenant_id'],
                ],
                fn (): QueryBuilder => $this->currentAssignmentQuery(
                    $connection->table('device_assignments as assignment'),
                    $schema,
                    'assignment',
                )
                    ->join('devices as device', 'device.id', '=', 'assignment.device_id')
                    ->join('assets as vehicle', 'vehicle.id', '=', 'assignment.assignable_id')
                    ->join('clients as client', 'client.id', '=', 'vehicle.client_id')
                    ->join('sites as site', 'site.id', '=', 'client.site_id')
                    ->where('assignment.assignable_type', 'vehicle')
                    ->whereNotNull('device.tenant_id')->whereNotNull('site.tenant_id')
                    ->whereColumn('device.tenant_id', '!=', 'site.tenant_id')
                    ->select('assignment.id as record_id'),
            ),
            $this->queryCheck(
                $connection,
                $schema,
                'vehicle_site_home_site_canonical_conflict',
                ['assets' => ['id', 'site_id', 'home_site_id']],
                fn (): QueryBuilder => $connection->table('assets')
                    ->whereNotNull('site_id')->whereNotNull('home_site_id')
                    ->whereColumn('site_id', '!=', 'home_site_id')
                    ->select('id as record_id'),
            ),
            $this->queryCheck(
                $connection,
                $schema,
                'vehicle_site_client_site_canonical_conflict',
                ['assets' => ['id', 'site_id', 'client_id'], 'clients' => ['id', 'site_id']],
                fn (): QueryBuilder => $connection->table('assets as vehicle')
                    ->join('clients as client', 'client.id', '=', 'vehicle.client_id')
                    ->whereNotNull('vehicle.site_id')->whereNotNull('client.site_id')
                    ->whereColumn('vehicle.site_id', '!=', 'client.site_id')
                    ->select('vehicle.id as record_id'),
            ),
            $this->queryCheck(
                $connection,
                $schema,
                'vehicle_home_site_client_site_canonical_conflict',
                ['assets' => ['id', 'home_site_id', 'client_id'], 'clients' => ['id', 'site_id']],
                fn (): QueryBuilder => $connection->table('assets as vehicle')
                    ->join('clients as client', 'client.id', '=', 'vehicle.client_id')
                    ->whereNotNull('vehicle.home_site_id')->whereNotNull('client.site_id')
                    ->whereColumn('vehicle.home_site_id', '!=', 'client.site_id')
                    ->select('vehicle.id as record_id'),
            ),
            $this->deviceActiveCanonicalSiteConflict($connection, $schema),
            $this->queryCheck(
                $connection,
                $schema,
                'device_assignment_staff_site_legacy_id_mismatch',
                [
                    'device_assignments' => ['id', 'device_id', 'assignable_type', 'assignable_id', 'released_at'],
                    'devices' => ['id', 'tenant_id'],
                    'users' => ['id'],
                    'hr_employee_profiles' => ['user_id', 'primary_site_id'],
                    'sites' => ['id', 'tenant_id'],
                ],
                fn (): QueryBuilder => $this->currentAssignmentQuery(
                    $connection->table('device_assignments as assignment'),
                    $schema,
                    'assignment',
                )
                    ->join('devices as device', 'device.id', '=', 'assignment.device_id')
                    ->join('users as staff', 'staff.id', '=', 'assignment.assignable_id')
                    ->join('hr_employee_profiles as profile', 'profile.user_id', '=', 'staff.id')
                    ->join('sites as site', 'site.id', '=', 'profile.primary_site_id')
                    ->where('assignment.assignable_type', 'staff')
                    ->whereNotNull('device.tenant_id')->whereNotNull('site.tenant_id')
                    ->whereColumn('device.tenant_id', '!=', 'site.tenant_id')
                    ->select('assignment.id as record_id'),
            ),
        ];
    }

    private function currentAssignmentQuery(
        QueryBuilder $query,
        SchemaBuilder $schema,
        ?string $alias = null,
    ): QueryBuilder {
        $prefix = $alias === null ? '' : $alias.'.';
        $query->whereNull($prefix.'released_at');

        if ($schema->hasColumn('device_assignments', 'assigned_at')) {
            $query->where($prefix.'assigned_at', '<=', now());
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function deviceActiveCanonicalSiteConflict(Connection $connection, SchemaBuilder $schema): array
    {
        return $this->queryCheck(
            $connection,
            $schema,
            'device_active_canonical_site_conflict',
            [
                'device_assignments' => ['device_id', 'assignable_type', 'assignable_id', 'assigned_at', 'released_at'],
                'sites' => ['id'],
                'site_rooms' => ['id', 'site_id'],
                'clients' => ['id', 'site_id'],
                'users' => ['id'],
                'hr_employee_profiles' => ['user_id', 'primary_site_id'],
                'assets' => ['id', 'site_id', 'home_site_id', 'client_id'],
            ],
            function () use ($connection, $schema): QueryBuilder {
                $sourceQueries = [
                    $this->currentAssignmentQuery($connection->table('device_assignments as assignment'), $schema, 'assignment')
                        ->join('sites as canonical_site', 'canonical_site.id', '=', 'assignment.assignable_id')
                        ->where('assignment.assignable_type', 'site')
                        ->select('assignment.device_id', 'canonical_site.id as canonical_site_id'),
                    $this->currentAssignmentQuery($connection->table('device_assignments as assignment'), $schema, 'assignment')
                        ->join('site_rooms as room', 'room.id', '=', 'assignment.assignable_id')
                        ->join('sites as canonical_site', 'canonical_site.id', '=', 'room.site_id')
                        ->where('assignment.assignable_type', 'room')
                        ->select('assignment.device_id', 'canonical_site.id as canonical_site_id'),
                    $this->currentAssignmentQuery($connection->table('device_assignments as assignment'), $schema, 'assignment')
                        ->join('clients as client', 'client.id', '=', 'assignment.assignable_id')
                        ->join('sites as canonical_site', 'canonical_site.id', '=', 'client.site_id')
                        ->where('assignment.assignable_type', 'client')
                        ->select('assignment.device_id', 'canonical_site.id as canonical_site_id'),
                    $this->currentAssignmentQuery($connection->table('device_assignments as assignment'), $schema, 'assignment')
                        ->join('users as staff', 'staff.id', '=', 'assignment.assignable_id')
                        ->join('hr_employee_profiles as profile', 'profile.user_id', '=', 'staff.id')
                        ->join('sites as canonical_site', 'canonical_site.id', '=', 'profile.primary_site_id')
                        ->where('assignment.assignable_type', 'staff')
                        ->select('assignment.device_id', 'canonical_site.id as canonical_site_id'),
                    $this->currentAssignmentQuery($connection->table('device_assignments as assignment'), $schema, 'assignment')
                        ->join('assets as vehicle', 'vehicle.id', '=', 'assignment.assignable_id')
                        ->join('sites as canonical_site', 'canonical_site.id', '=', 'vehicle.site_id')
                        ->where('assignment.assignable_type', 'vehicle')
                        ->select('assignment.device_id', 'canonical_site.id as canonical_site_id'),
                    $this->currentAssignmentQuery($connection->table('device_assignments as assignment'), $schema, 'assignment')
                        ->join('assets as vehicle', 'vehicle.id', '=', 'assignment.assignable_id')
                        ->join('sites as canonical_site', 'canonical_site.id', '=', 'vehicle.home_site_id')
                        ->where('assignment.assignable_type', 'vehicle')
                        ->select('assignment.device_id', 'canonical_site.id as canonical_site_id'),
                    $this->currentAssignmentQuery($connection->table('device_assignments as assignment'), $schema, 'assignment')
                        ->join('assets as vehicle', 'vehicle.id', '=', 'assignment.assignable_id')
                        ->join('clients as client', 'client.id', '=', 'vehicle.client_id')
                        ->join('sites as canonical_site', 'canonical_site.id', '=', 'client.site_id')
                        ->where('assignment.assignable_type', 'vehicle')
                        ->select('assignment.device_id', 'canonical_site.id as canonical_site_id'),
                ];

                $union = array_shift($sourceQueries);
                foreach ($sourceQueries as $sourceQuery) {
                    $union->unionAll($sourceQuery);
                }

                return $connection->query()
                    ->fromSub($union, 'canonical_assignment_sites')
                    ->whereNotNull('canonical_site_id')
                    ->groupBy('device_id')
                    ->havingRaw('COUNT(DISTINCT canonical_site_id) > 1')
                    ->select('device_id as record_id');
            },
        );
    }

    /** @return array<string, mixed> */
    private function assignmentTargetSiteLegacyMismatch(
        Connection $connection,
        SchemaBuilder $schema,
        string $check,
        string $assignableType,
        string $targetTable,
        string $targetSiteColumn,
    ): array {
        return $this->queryCheck(
            $connection,
            $schema,
            $check,
            [
                'device_assignments' => ['id', 'device_id', 'assignable_type', 'assignable_id', 'released_at'],
                'devices' => ['id', 'tenant_id'],
                $targetTable => ['id', $targetSiteColumn],
                'sites' => ['id', 'tenant_id'],
            ],
            fn (): QueryBuilder => $this->currentAssignmentQuery(
                $connection->table('device_assignments as assignment'),
                $schema,
                'assignment',
            )
                ->join('devices as device', 'device.id', '=', 'assignment.device_id')
                ->join("{$targetTable} as target", 'target.id', '=', 'assignment.assignable_id')
                ->join('sites as site', 'site.id', '=', "target.{$targetSiteColumn}")
                ->where('assignment.assignable_type', $assignableType)
                ->whereNotNull('device.tenant_id')->whereNotNull('site.tenant_id')
                ->whereColumn('device.tenant_id', '!=', 'site.tenant_id')
                ->select('assignment.id as record_id'),
        );
    }

    /** @return array<string, mixed> */
    private function relationLegacyMismatch(
        Connection $connection,
        SchemaBuilder $schema,
        string $check,
        string $childTable,
        string $foreignKey,
        string $parentTable,
    ): array {
        return $this->queryCheck(
            $connection,
            $schema,
            $check,
            [$childTable => ['id', $foreignKey, 'tenant_id'], $parentTable => ['id', 'tenant_id']],
            fn (): QueryBuilder => $connection->table("{$childTable} as child")
                ->join("{$parentTable} as parent", 'parent.id', '=', "child.{$foreignKey}")
                ->whereNotNull('child.tenant_id')->whereNotNull('parent.tenant_id')
                ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')
                ->select('child.id as record_id'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function orphanChecks(Connection $connection, SchemaBuilder $schema): array
    {
        return [
            $this->polymorphicAssignmentOrphan($connection, $schema, 'site', 'sites'),
            $this->polymorphicAssignmentOrphan($connection, $schema, 'room', 'site_rooms'),
            $this->polymorphicAssignmentOrphan($connection, $schema, 'client', 'clients'),
            $this->polymorphicAssignmentOrphan($connection, $schema, 'staff', 'users'),
            $this->polymorphicAssignmentOrphan($connection, $schema, 'vehicle', 'assets'),
            $this->queryCheck(
                $connection,
                $schema,
                'device_assignment_unknown_target_type',
                ['device_assignments' => ['id', 'assignable_type']],
                fn (): QueryBuilder => $connection->table('device_assignments')
                    ->whereNotIn('assignable_type', self::DEVICE_ASSIGNMENT_TYPES)
                    ->select('id as record_id'),
            ),
            $this->orphanRelation($connection, $schema, 'device_assignment_device_missing', 'device_assignments', 'device_id', 'devices'),
            $this->queryCheck(
                $connection,
                $schema,
                'provider_site_mapping_without_connection',
                ['integration_site_configs' => ['id', 'tenant_id', 'provider'], 'integrations' => ['id', 'tenant_id', 'provider']],
                fn (): QueryBuilder => $connection->table('integration_site_configs as mapping')
                    ->leftJoin('integrations as connection', function ($join): void {
                        $join->on('connection.provider', '=', 'mapping.provider')
                            ->on('connection.tenant_id', '=', 'mapping.tenant_id');
                    })
                    ->whereNull('connection.id')
                    ->select('mapping.id as record_id'),
            ),
            $this->orphanRelation($connection, $schema, 'provider_site_mapping_site_missing', 'integration_site_configs', 'site_id', 'sites'),
            $this->orphanRelation($connection, $schema, 'it_ticket_link_ticket_missing', 'it_ticket_links', 'ticket_id', 'it_tickets'),
            ...array_map(
                fn (array $target): array => $this->ticketLinkTargetOrphan($connection, $schema, $target),
                self::IT_TICKET_LINK_TARGETS,
            ),
            $this->unknownTicketLinkTargetType($connection, $schema),
            $this->orphanRelation($connection, $schema, 'device_asset_link_device_missing', 'device_asset_links', 'device_id', 'devices'),
            $this->orphanRelation($connection, $schema, 'device_asset_link_asset_missing', 'device_asset_links', 'asset_id', 'assets'),
            $this->orphanRelation($connection, $schema, 'monitor_device_missing', 'monitors', 'device_id', 'devices'),
            $this->orphanRelation($connection, $schema, 'monitor_profile_missing', 'monitors', 'profile_id', 'monitoring_profiles'),
            $this->orphanRelation($connection, $schema, 'monitor_collector_missing', 'monitors', 'collector_id', 'monitoring_collectors', true),
        ];
    }

    /** @return array<string, mixed> */
    private function polymorphicAssignmentOrphan(
        Connection $connection,
        SchemaBuilder $schema,
        string $assignableType,
        string $targetTable,
    ): array {
        return $this->queryCheck(
            $connection,
            $schema,
            "device_assignment_{$assignableType}_target_missing",
            [
                'device_assignments' => ['id', 'assignable_type', 'assignable_id'],
                $targetTable => ['id'],
            ],
            fn (): QueryBuilder => $connection->table('device_assignments as child')
                ->leftJoin("{$targetTable} as parent", 'parent.id', '=', 'child.assignable_id')
                ->where('child.assignable_type', $assignableType)
                ->whereNull('parent.id')
                ->select('child.id as record_id'),
        );
    }

    /** @param array{key: string, table: string, types: list<string>} $target
     * @return array<string, mixed>
     */
    private function ticketLinkTargetOrphan(Connection $connection, SchemaBuilder $schema, array $target): array
    {
        return $this->queryCheck(
            $connection,
            $schema,
            "it_ticket_link_{$target['key']}_target_missing",
            ['it_ticket_links' => ['id', 'linkable_type', 'linkable_id'], $target['table'] => ['id']],
            fn (): QueryBuilder => $connection->table('it_ticket_links as link')
                ->leftJoin("{$target['table']} as target", 'target.id', '=', 'link.linkable_id')
                ->whereIn('link.linkable_type', $target['types'])
                ->whereNull('target.id')
                ->select('link.id as record_id'),
        );
    }

    /** @return array<string, mixed> */
    private function unknownTicketLinkTargetType(Connection $connection, SchemaBuilder $schema): array
    {
        $knownTypes = collect(self::IT_TICKET_LINK_TARGETS)->flatMap(
            fn (array $target): array => $target['types'],
        )->unique()->values()->all();

        return $this->queryCheck(
            $connection,
            $schema,
            'it_ticket_link_unknown_target_type',
            ['it_ticket_links' => ['id', 'linkable_type']],
            fn (): QueryBuilder => $connection->table('it_ticket_links')
                ->whereNotIn('linkable_type', $knownTypes)
                ->select('id as record_id'),
        );
    }

    /** @return array<string, mixed> */
    private function orphanRelation(
        Connection $connection,
        SchemaBuilder $schema,
        string $check,
        string $childTable,
        string $foreignKey,
        string $parentTable,
        bool $nullable = false,
    ): array {
        return $this->queryCheck(
            $connection,
            $schema,
            $check,
            [$childTable => ['id', $foreignKey], $parentTable => ['id']],
            function () use ($connection, $childTable, $foreignKey, $parentTable, $nullable): QueryBuilder {
                $query = $connection->table("{$childTable} as child")
                    ->leftJoin("{$parentTable} as parent", 'parent.id', '=', "child.{$foreignKey}")
                    ->whereNull('parent.id')
                    ->select('child.id as record_id');

                return $nullable ? $query->whereNotNull("child.{$foreignKey}") : $query;
            },
        );
    }

    /** @return array<string, mixed> */
    private function nullSiteTickets(Connection $connection, SchemaBuilder $schema): array
    {
        $missing = $this->missingSchema($schema, ['it_tickets' => ['id', 'site_id']]);
        if ($missing !== []) {
            return [
                'status' => 'not_available',
                'total' => 0,
                'with_explicit_organisation_wide_evidence' => 0,
                'without_explicit_organisation_wide_evidence' => 0,
                'evidence_field_status' => 'not_available',
                'fingerprints' => [],
                'missing_schema' => $missing,
            ];
        }

        $base = $connection->table('it_tickets')->whereNull('site_id');
        $total = (clone $base)->count();
        $evidence = 0;
        $evidenceField = 'not_available';

        foreach (['is_organisation_wide', 'is_organization_wide'] as $column) {
            if ($schema->hasColumn('it_tickets', $column)) {
                $evidence = (clone $base)->where($column, true)->count();
                $evidenceField = $column;
                break;
            }
        }

        if ($evidenceField === 'not_available' && $schema->hasColumn('it_tickets', 'scope_type')) {
            $evidence = (clone $base)->whereIn('scope_type', ['organisation_wide', 'organization_wide'])->count();
            $evidenceField = 'scope_type';
        }

        return [
            'status' => $total === 0 ? 'clear' : 'finding',
            'total' => $total,
            'with_explicit_organisation_wide_evidence' => $evidence,
            'without_explicit_organisation_wide_evidence' => max(0, $total - $evidence),
            'evidence_field_status' => $evidenceField,
            'fingerprints' => (clone $base)->orderBy('id')->limit(self::MAX_FINGERPRINTS_PER_CHECK)->pluck('id')
                ->map(fn (mixed $id): string => $this->fingerprint('null_site_ticket', [(string) $id]))->all(),
            'truncated' => $total > self::MAX_FINGERPRINTS_PER_CHECK,
        ];
    }

    /** @return array<string, mixed> */
    private function deviceAssignmentSummary(Connection $connection, SchemaBuilder $schema): array
    {
        $missing = $this->missingSchema($schema, [
            'devices' => ['id'],
            'device_assignments' => ['id', 'device_id', 'assignable_type', 'assigned_at', 'released_at'],
        ]);
        if ($missing !== []) {
            return [
                'status' => 'not_available',
                'unassigned_devices' => 0,
                'ambiguously_assigned_devices' => 0,
                'future_assignment_rows' => 0,
                'unassigned_fingerprints' => [],
                'ambiguous_fingerprints' => [],
                'future_assignment_fingerprints' => [],
                'missing_schema' => $missing,
            ];
        }

        $devices = $connection->table('devices as device');
        if ($schema->hasColumn('devices', 'deleted_at')) {
            $devices->whereNull('device.deleted_at');
        }

        $activeAssignments = $this->currentAssignmentQuery(
            $connection->table('device_assignments'),
            $schema,
        )
            ->whereIn('assignable_type', self::DEVICE_ASSIGNMENT_TYPES)
            ->select('device_id')
            ->selectRaw('COUNT(*) AS assignment_count')
            ->groupBy('device_id');

        $rows = $devices
            ->leftJoinSub($activeAssignments, 'active_assignment', 'active_assignment.device_id', '=', 'device.id')
            ->select('device.id')
            ->selectRaw('COALESCE(active_assignment.assignment_count, 0) AS assignment_count')
            ->orderBy('device.id')
            ->get();
        $unassigned = $rows->filter(fn (object $row): bool => (int) $row->assignment_count === 0);
        $ambiguous = $rows->filter(fn (object $row): bool => (int) $row->assignment_count > 1);
        $futureAssignments = $connection->table('device_assignments')
            ->whereNull('released_at')
            ->where('assigned_at', '>', now())
            ->orderBy('id');
        $futureAssignmentCount = (clone $futureAssignments)->count();
        $futureAssignmentRows = $futureAssignments->limit(self::MAX_FINGERPRINTS_PER_CHECK)->get(['id']);

        return [
            'status' => $unassigned->isEmpty() && $ambiguous->isEmpty() && $futureAssignmentCount === 0
                ? 'clear'
                : 'finding',
            'unassigned_devices' => $unassigned->count(),
            'ambiguously_assigned_devices' => $ambiguous->count(),
            'future_assignment_rows' => $futureAssignmentCount,
            'unassigned_fingerprints' => $this->fingerprintRows('unassigned_device', $unassigned),
            'ambiguous_fingerprints' => $this->fingerprintRows('ambiguous_device', $ambiguous),
            'future_assignment_fingerprints' => $this->fingerprintRows('future_device_assignment', $futureAssignmentRows),
            'truncated' => $unassigned->count() > self::MAX_FINGERPRINTS_PER_CHECK
                || $ambiguous->count() > self::MAX_FINGERPRINTS_PER_CHECK
                || $futureAssignmentCount > self::MAX_FINGERPRINTS_PER_CHECK,
        ];
    }

    /** @param list<string> $tables
     * @return list<array{table: string, index: string, columns: list<string>, unique: bool}>
     */
    private function tenantLeadingIndexes(SchemaBuilder $schema, array $tables): array
    {
        $indexes = [];

        foreach ($tables as $table) {
            foreach ($schema->getIndexes($table) as $index) {
                if (($index['columns'][0] ?? null) !== 'tenant_id') {
                    continue;
                }

                $indexes[] = [
                    'table' => $table,
                    'index' => (string) $index['name'],
                    'columns' => array_values($index['columns']),
                    'unique' => (bool) $index['unique'],
                ];
            }
        }

        return collect($indexes)->sortBy(fn (array $index): string => $index['table'].'|'.$index['index'])->values()->all();
    }

    /** @param array<string, list<string>> $required
     * @return array<string, mixed>
     */
    private function queryCheck(
        Connection $connection,
        SchemaBuilder $schema,
        string $check,
        array $required,
        Closure $query,
    ): array {
        $missing = $this->missingSchema($schema, $required);
        if ($missing !== []) {
            return [
                'check' => $check,
                'status' => 'not_available',
                'count' => 0,
                'fingerprints' => [],
                'missing_schema' => $missing,
            ];
        }

        /** @var QueryBuilder $findings */
        $findings = $query();
        $count = $connection->query()->fromSub(clone $findings, 'audit_findings')->count();
        $rows = $findings->orderBy('record_id')->limit(self::MAX_FINGERPRINTS_PER_CHECK)->get();

        return [
            'check' => $check,
            'status' => $count === 0 ? 'clear' : 'finding',
            'count' => $count,
            'fingerprints' => $this->fingerprintRows($check, $rows),
            'truncated' => $count > self::MAX_FINGERPRINTS_PER_CHECK,
        ];
    }

    /** @param array<string, list<string>> $required
     * @return list<string>
     */
    private function missingSchema(SchemaBuilder $schema, array $required): array
    {
        $missing = [];
        foreach ($required as $table => $columns) {
            if (! $schema->hasTable($table)) {
                $missing[] = $table.'.*';

                continue;
            }
            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        sort($missing, SORT_STRING);

        return $missing;
    }

    /** @param Collection<int, object> $rows
     * @return list<string>
     */
    private function fingerprintRows(string $label, Collection $rows): array
    {
        return $rows->take(self::MAX_FINGERPRINTS_PER_CHECK)
            ->map(fn (object $row): string => $this->fingerprint(
                $label,
                array_map(static fn (mixed $value): string => (string) $value, array_values((array) $row)),
            ))->values()->all();
    }

    /** @param list<string> $parts */
    private function fingerprint(string $label, array $parts): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Application key unavailable for audit redaction.');
        }

        return 'fp:'.substr(hash_hmac(
            'sha256',
            json_encode([$label, $parts], JSON_THROW_ON_ERROR),
            $key,
        ), 0, 20);
    }

    /** @param array<string, mixed> $report */
    private function toMarkdown(array $report): string
    {
        $lines = [
            '# IT, Security & Devices single-tenant data audit',
            '',
            '> Source: local development database snapshot. This is not production state.',
            '',
            '> This is read-only, redacted Task 1 no-regression evidence. It does not prove single-tenant remediation is complete.',
            '',
            '## Summary',
            '',
            '| Check | Count |',
            '| --- | ---: |',
        ];

        foreach ($report['summary'] as $key => $count) {
            $lines[] = '| '.str_replace('_', ' ', (string) $key).' | '.(int) $count.' |';
        }

        $lines = [...$lines, '', '## Legacy boundary identifiers', '', '| Fingerprint | Rows | Sources | Tables |', '| --- | ---: | ---: | ---: |'];
        foreach ($report['legacy_ids'] as $legacyId) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d |',
                $legacyId['fingerprint'],
                $legacyId['row_count'],
                $legacyId['source_count'],
                $legacyId['table_count'],
            );
        }

        $lines = [...$lines, '', '## Legacy boundary sources', '', '| Source | Status | Rows | Null rows | Distinct values |', '| --- | --- | ---: | ---: | ---: |'];
        foreach ($report['legacy_id_sources'] as $source) {
            $lines[] = sprintf(
                '| %s | %s | %d | %d | %d |',
                $source['source'],
                $source['status'],
                $source['row_count'],
                $source['null_rows'],
                $source['distinct_values'],
            );
        }

        $lines = [...$lines, '', '## Global identity checks', '', '| Identity | Table | Status | Duplicate groups | Duplicate rows |', '| --- | --- | --- | ---: | ---: |'];
        foreach ($report['global_key_checks'] as $check) {
            $lines[] = sprintf(
                '| %s | %s | %s | %d | %d |',
                $check['key'],
                $check['table'],
                $check['status'],
                $check['duplicate_groups'],
                $check['duplicate_rows'],
            );
        }

        $lines = [
            ...$lines,
            '',
            '## Inbound email ambiguity',
            '',
            '- Inbound email reference ambiguity: '.$report['inbound_email_ambiguity']['status'],
            '- Ambiguous reference groups: '.(int) $report['inbound_email_ambiguity']['ambiguous_reference_groups'],
            '- Tickets in ambiguous reference groups: '.(int) $report['inbound_email_ambiguity']['ambiguous_ticket_rows'],
        ];

        $lines = [...$lines, '', '## Provenance checks', '', '| Check | Status | Count |', '| --- | --- | ---: |'];
        foreach ($report['provenance_checks'] as $check) {
            $lines[] = sprintf('| %s | %s | %d |', $check['check'], $check['status'], $check['count']);
        }

        $lines = [...$lines, '', '## Orphan checks', '', '| Check | Status | Count |', '| --- | --- | ---: |'];
        foreach ($report['orphan_checks'] as $check) {
            $lines[] = sprintf('| %s | %s | %d |', $check['check'], $check['status'], $check['count']);
        }

        $lines = [
            ...$lines,
            '',
            '## Null-Site tickets and device assignment posture',
            '',
            '- Null-Site ticket audit status: '.$report['null_site_tickets']['status'],
            '- Organisation-wide evidence marker: '.$report['null_site_tickets']['evidence_field_status'],
            '- Null-Site tickets: '.(int) $report['null_site_tickets']['total'],
            '- Null-Site tickets with explicit organisation-wide evidence: '.(int) $report['null_site_tickets']['with_explicit_organisation_wide_evidence'],
            '- Null-Site tickets without explicit organisation-wide evidence: '.(int) $report['null_site_tickets']['without_explicit_organisation_wide_evidence'],
            '- Device assignment audit status: '.$report['device_assignments']['status'],
            '- Unassigned devices: '.(int) $report['device_assignments']['unassigned_devices'],
            '- Ambiguously assigned devices: '.(int) $report['device_assignments']['ambiguously_assigned_devices'],
            '- Future-dated assignment rows: '.(int) $report['device_assignments']['future_assignment_rows'],
            '',
            '## Tenant-leading indexes requiring replacement planning',
            '',
            '| Table | Index | Columns | Unique |',
            '| --- | --- | --- | --- |',
        ];
        foreach ($report['tenant_leading_indexes'] as $index) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $index['table'],
                $index['index'],
                implode(', ', $index['columns']),
                $index['unique'] ? 'yes' : 'no',
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
