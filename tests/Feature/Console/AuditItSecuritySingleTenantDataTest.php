<?php

use App\Console\Commands\AuditItSecuritySingleTenantData;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

if (getenv('IT_SECURITY_USE_PREBUILT_TEST_DATABASE') === '1') {
    $appEnvironment = getenv('APP_ENV');
    $databaseConnection = getenv('DB_CONNECTION');
    $databasePath = getenv('DB_DATABASE');
    $safeRoot = realpath(__DIR__.'/../../../storage/framework/testing');
    $resolvedDatabasePath = is_string($databasePath) ? realpath($databasePath) : false;

    if ($appEnvironment !== 'testing'
        || $databaseConnection !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || $safeRoot === false
        || $resolvedDatabasePath === false
        || ! str_starts_with(str_replace('\\', '/', $resolvedDatabasePath), str_replace('\\', '/', $safeRoot).'/')
    ) {
        throw new RuntimeException(
            'IT_SECURITY_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing and a file-backed SQLite database inside storage/framework/testing.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

beforeEach(function (): void {
    if (getenv('IT_SECURITY_USE_PREBUILT_TEST_DATABASE') === '1') {
        createSingleTenantAuditTestSchema();
    }
});

it('audits single-application collisions and provenance without mutating data or exposing values', function () {
    $canSeedTicketReferenceCollision = ! auditColumnHasGlobalUniqueIndex('it_tickets', 'reference');
    $canSeedMissingAssignmentDevice = ! auditColumnHasForeignKey('device_assignments', 'device_id');
    $canSeedMissingLinkedTicket = ! auditColumnHasForeignKey('it_ticket_links', 'ticket_id');

    $requesterId = DB::table('users')->insertGetId([
        'name' => 'Private Audit Person',
        'email' => 'private-audit-person@example.test',
        'password' => 'not-a-real-password',
        'organization_id' => 91377,
    ]);
    $ticketSiteId = DB::table('sites')->insertGetId(['tenant_id' => 11, 'name' => 'Audit site A']);
    // Both Sites intentionally share one legacy ID: canonical Site-ID
    // contradictions must not disappear in the single-application state.
    $otherSiteId = DB::table('sites')->insertGetId(['tenant_id' => 11, 'name' => 'Audit site B']);
    $otherTeamId = DB::table('it_teams')->insertGetId([
        'tenant_id' => 22,
        'name' => 'Audit team',
        'is_active' => true,
    ]);
    DB::table('hr_employee_profiles')->insert(auditFixtureRow('hr_employee_profiles', [
        'user_id' => $requesterId,
        'primary_site_id' => $ticketSiteId,
        'tenant_id' => 11,
        'employee_number' => 'EMP-AUDIT-'.$requesterId,
        'work_email' => 'private-audit-person@example.test',
        'position_title' => 'Audit Fixture',
        'position_role' => 'auditor',
        'employment_type' => 'full_time',
        'start_date' => today()->subDay(),
        'is_active' => true,
        'secondary_site_ids' => '[]',
        'created_by' => $requesterId,
        'updated_by' => $requesterId,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    DB::table('site_rooms')->insert(auditFixtureRow('site_rooms', [
        'site_id' => $ticketSiteId,
        'tenant_id' => 71234,
        'name' => 'Audit room',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    DB::table('location_hardware')->insert(auditFixtureRow('location_hardware', [
        'tenant_id' => 81234,
        'site_id' => $ticketSiteId,
        'provider' => 'manual',
        'category' => 'other',
        'name' => 'Audit hardware',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    $vehicleClientId = DB::table('clients')->insertGetId(auditFixtureRow('clients', [
        'site_id' => $otherSiteId,
        'first_name' => 'Audit',
        'last_name' => 'Client',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    $vehicleAssetId = DB::table('assets')->insertGetId(auditFixtureRow('assets', [
        'site_id' => $ticketSiteId,
        'home_site_id' => $otherSiteId,
        'client_id' => $vehicleClientId,
        'name' => 'Audit vehicle',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    DB::table('it_tickets')->insert([
        [
            'tenant_id' => 11,
            'reference' => 'TOP-SECRET-COLLISION',
            'title' => 'First private ticket',
            'requester_user_id' => $requesterId,
            'site_id' => $ticketSiteId,
            'team_id' => $otherTeamId,
            'category' => 'network',
            'priority' => 'normal',
            'status' => 'open',
            'work_type' => 'incident',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'tenant_id' => 22,
            'reference' => $canSeedTicketReferenceCollision ? 'TOP-SECRET-COLLISION' : 'TOP-SECRET-SECOND',
            'title' => 'Second private ticket',
            'requester_user_id' => $requesterId,
            'site_id' => null,
            'team_id' => null,
            'category' => 'network',
            'priority' => 'normal',
            'status' => 'open',
            'work_type' => 'incident',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $existingTicketId = (int) DB::table('it_tickets')->orderBy('id')->value('id');

    $ticketLinks = [
        ...($canSeedMissingLinkedTicket ? [[
            'tenant_id' => 11,
            'ticket_id' => 999997,
            'relationship' => 'affected_site',
            'linkable_type' => 'site',
            'linkable_id' => $ticketSiteId,
            'created_at' => now(),
            'updated_at' => now(),
        ]] : []),
        [
            'tenant_id' => 11,
            'ticket_id' => $existingTicketId,
            'relationship' => 'affected_device',
            'linkable_type' => 'security_device',
            'linkable_id' => 999996,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'tenant_id' => 11,
            'ticket_id' => $existingTicketId,
            'relationship' => 'source_alert',
            'linkable_type' => 'control_room_alert',
            'linkable_id' => 999995,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'tenant_id' => 11,
            'ticket_id' => $existingTicketId,
            'relationship' => 'related_incident',
            'linkable_type' => 'it_ticket',
            'linkable_id' => 999994,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'tenant_id' => 11,
            'ticket_id' => $existingTicketId,
            'relationship' => 'affected_service',
            'linkable_type' => 'it_service',
            'linkable_id' => 999993,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'tenant_id' => 11,
            'ticket_id' => $existingTicketId,
            'relationship' => 'source_record',
            'linkable_type' => 'secret-unknown-link-type',
            'linkable_id' => 999992,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ];
    DB::table('it_ticket_links')->insert($ticketLinks);

    $unassignedId = insertAuditDevice('audit-device-unassigned', 11);
    $ambiguousId = insertAuditDevice('audit-device-ambiguous', 11);
    $orphanedId = insertAuditDevice('audit-device-orphaned', 11);
    $clientOrphanedId = insertAuditDevice('audit-device-client-orphaned', 11);
    $vehicleId = insertAuditDevice('audit-device-vehicle-provenance', 11);

    $deviceAssignments = [
        [
            'device_id' => $ambiguousId,
            'assignable_type' => 'site',
            'assignable_id' => $ticketSiteId,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'device_id' => $ambiguousId,
            'assignable_type' => 'site',
            'assignable_id' => $otherSiteId,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'device_id' => $orphanedId,
            'assignable_type' => 'site',
            'assignable_id' => 999999,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'device_id' => $clientOrphanedId,
            'assignable_type' => 'client',
            'assignable_id' => 888888,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        ...($canSeedMissingAssignmentDevice ? [[
            'device_id' => 999998,
            'assignable_type' => 'staff',
            'assignable_id' => $requesterId,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]] : []),
        [
            'device_id' => $vehicleId,
            'assignable_type' => 'vehicle',
            'assignable_id' => $vehicleAssetId,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ];
    DB::table('device_assignments')->insert($deviceAssignments);

    DB::table('integration_site_configs')->insert([
        'tenant_id' => 11,
        'site_id' => $ticketSiteId,
        'provider' => 'secret-provider-name',
        'status' => 'disconnected',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('integrations')->insert(auditFixtureRow('integrations', [
        'tenant_id' => 22,
        'provider' => 'secret-provider-name',
        'display_name' => 'Private integration',
        'status' => 'inactive',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $before = auditTableCounts();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $exit = Artisan::call('it-security:audit-single-tenant-data', [
        '--format' => 'json',
    ]);

    expect($exit)->toBe(0);

    $output = trim(Artisan::output());
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($report)
        ->toHaveKey('contract_version', 1)
        ->toHaveKey('scope', 'single_application')
        ->toHaveKey('read_only', true)
        ->toHaveKey('summary')
        ->toHaveKey('legacy_ids')
        ->toHaveKey('legacy_id_sources')
        ->toHaveKey('global_key_checks')
        ->toHaveKey('inbound_email_ambiguity')
        ->toHaveKey('provenance_checks')
        ->toHaveKey('orphan_checks')
        ->toHaveKey('null_site_tickets')
        ->toHaveKey('device_assignments')
        ->toHaveKey('tenant_leading_indexes');

    expect(collect($report['legacy_ids'])->pluck('row_count')->sum())->toBeGreaterThanOrEqual(3)
        ->and(collect($report['legacy_ids'])->pluck('fingerprint')->filter()->count())->toBeGreaterThanOrEqual(3)
        ->and(collect($report['legacy_id_sources'])->firstWhere('source', 'users.organization_id')['status'])
        ->toBe('audited')
        ->and(collect($report['legacy_id_sources'])->firstWhere('source', 'hr_employee_profiles.tenant_id')['status'])
        ->toBe('audited')
        ->and(collect($report['legacy_id_sources'])->firstWhere('source', 'site_rooms.tenant_id')['status'])
        ->toBe('audited')
        ->and(collect($report['legacy_id_sources'])->firstWhere('source', 'location_hardware.tenant_id')['status'])
        ->toBe('audited');

    $ticketKey = collect($report['global_key_checks'])
        ->firstWhere('key', 'ticket_reference');
    expect($ticketKey)
        ->not->toBeNull()
        ->and($ticketKey['duplicate_groups'])->toBe($canSeedTicketReferenceCollision ? 1 : 0)
        ->and($ticketKey['duplicate_rows'])->toBe($canSeedTicketReferenceCollision ? 2 : 0)
        ->and($ticketKey['status'])->toBe($canSeedTicketReferenceCollision ? 'collision' : 'clear');

    expect($report['inbound_email_ambiguity'])
        ->toMatchArray([
            'status' => $canSeedTicketReferenceCollision ? 'ambiguous' : 'clear',
            'ambiguous_reference_groups' => $canSeedTicketReferenceCollision ? 1 : 0,
            'ambiguous_ticket_rows' => $canSeedTicketReferenceCollision ? 2 : 0,
        ]);

    expect(collect($report['global_key_checks'])->pluck('key')->all())->toEqualCanonicalizing([
        'ticket_reference',
        'sla_priority',
        'kb_slug',
        'mailbox_provider',
        'team_name',
        'queue_key',
        'service_key',
        'catalogue_slug',
        'catalogue_requester_idempotency',
        'provisioning_source_event',
        'collector_uuid',
        'monitoring_profile_name',
        'device_group_name',
        'integration_provider',
        'integration_provider_event',
        'queclink_preset_slug',
    ]);

    expect(collect($report['provenance_checks'])->firstWhere('check', 'ticket_team_legacy_id_mismatch')['count'])
        ->toBe(1)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'device_active_site_assignment_conflict')['count'])
        ->toBeGreaterThanOrEqual(1)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'device_assignment_vehicle_site_legacy_id_mismatch')['count'])
        ->toBe(0)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'device_assignment_vehicle_home_site_legacy_id_mismatch')['count'])
        ->toBe(0)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'device_assignment_vehicle_client_site_legacy_id_mismatch')['count'])
        ->toBe(0)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'vehicle_site_home_site_canonical_conflict')['count'])
        ->toBe(1)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'vehicle_site_client_site_canonical_conflict')['count'])
        ->toBe(1)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'device_active_canonical_site_conflict')['count'])
        ->toBeGreaterThanOrEqual(2)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'provider_site_mapping_site_legacy_id_mismatch')['count'])
        ->toBe(0)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'device_assignment_site_target_missing')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'device_assignment_client_target_missing')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'device_assignment_staff_target_missing')['count'])
        ->toBe(0)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'device_assignment_device_missing')['count'])
        ->toBe($canSeedMissingAssignmentDevice ? 1 : 0)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'provider_site_mapping_without_connection')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'it_ticket_link_ticket_missing')['count'])
        ->toBe($canSeedMissingLinkedTicket ? 1 : 0)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'it_ticket_link_security_device_target_missing')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'it_ticket_link_control_room_alert_target_missing')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'it_ticket_link_it_ticket_target_missing')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'it_ticket_link_it_service_target_missing')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'it_ticket_link_unknown_target_type')['count'])
        ->toBe(1)
        ->and($report['null_site_tickets']['total'])->toBe(1)
        ->and($report['null_site_tickets']['without_explicit_organisation_wide_evidence'])->toBe(1)
        ->and($report['device_assignments']['unassigned_devices'])->toBeGreaterThanOrEqual(1)
        ->and($report['device_assignments']['ambiguously_assigned_devices'])->toBeGreaterThanOrEqual(1);

    expect($output)
        ->not->toContain('TOP-SECRET-COLLISION')
        ->not->toContain('TOP-SECRET-SECOND')
        ->not->toContain('private-audit-person@example.test')
        ->not->toContain('Private Audit Person')
        ->not->toContain('secret-provider-name')
        ->not->toContain('91377')
        ->not->toContain('71234')
        ->not->toContain('81234')
        ->not->toContain('secret-unknown-link-type')
        ->and(auditTableCounts())->toBe($before);

    expect(auditReportKeys($report))->not->toContain(
        'value',
        'raw_value',
        'row_id',
        'sample',
        'email',
        'secret',
    );

    $unsafeQueries = collect($queries)->filter(
        fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|truncate|create)\b/i', $sql) === 1,
    );
    expect($unsafeQueries)->toBeEmpty();

    $repeatExit = Artisan::call('it-security:audit-single-tenant-data', ['--format' => 'json']);
    expect($repeatExit)->toBe(0)
        ->and(trim(Artisan::output()))->toBe($output);

    // Make the intentionally unused fixture explicit: it proves an ordinary
    // device with no active assignment is counted without exposing its ID.
    expect(DB::table('devices')->where('id', $unassignedId)->exists())->toBeTrue();
    foreach (['it_tickets', 'site_rooms', 'location_hardware'] as $table) {
        expect(collect($report['tenant_leading_indexes'])
            ->where('table', $table)
            ->pluck('index')
            ->values()
            ->all())->toEqualCanonicalizing(auditTenantLeadingIndexNames($table));
    }
});

it('renders bounded markdown and states that Task 1 is only a no-regression gate', function () {
    $exit = Artisan::call('it-security:audit-single-tenant-data');
    $output = Artisan::output();
    $evidenceMarker = collect(['is_organisation_wide', 'is_organization_wide', 'scope_type'])
        ->first(fn (string $column): bool => Schema::hasColumn('it_tickets', $column)) ?? 'not_available';

    expect($exit)->toBe(0)
        ->and($output)->toContain('# IT, Security & Devices single-tenant data audit')
        ->toContain('Source: local development database snapshot. This is not production state.')
        ->toContain('read-only')
        ->toContain('no-regression evidence')
        ->toContain('does not prove single-tenant remediation is complete')
        ->toContain('Inbound email reference ambiguity: clear')
        ->toContain('## Legacy boundary identifiers')
        ->toContain('## Legacy boundary sources')
        ->toContain('| users.organization_id | audited |')
        ->toContain('| hr_employee_profiles.tenant_id | audited |')
        ->toContain('Organisation-wide evidence marker: '.$evidenceMarker);

    $databaseName = (string) config('database.connections.mysql.database');
    if ($databaseName !== '') {
        expect($output)->not->toContain($databaseName);
    }
});

it('uses current assignment time scope while auditing orphan targets across all history', function () {
    $firstSiteId = DB::table('sites')->insertGetId(['tenant_id' => 11, 'name' => 'Current site']);
    $futureSiteId = DB::table('sites')->insertGetId(['tenant_id' => 11, 'name' => 'Future site']);
    $futureOnlyDeviceId = insertAuditDevice('audit-device-future-only', 11);
    $currentDeviceId = insertAuditDevice('audit-device-current-plus-future', 11);

    DB::table('device_assignments')->insert([
        [
            'device_id' => $futureOnlyDeviceId,
            'assignable_type' => 'site',
            'assignable_id' => $futureSiteId,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->addDay(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'device_id' => $currentDeviceId,
            'assignable_type' => 'site',
            'assignable_id' => $firstSiteId,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subDay(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'device_id' => $futureOnlyDeviceId,
            'assignable_type' => 'secret-invalid-assignment-type',
            'assignable_id' => $firstSiteId,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subDay(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'device_id' => $currentDeviceId,
            'assignable_type' => 'site',
            'assignable_id' => $futureSiteId,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->addDay(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'device_id' => $currentDeviceId,
            'assignable_type' => 'site',
            'assignable_id' => 777777,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subDays(2),
            'released_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $exit = Artisan::call('it-security:audit-single-tenant-data', ['--format' => 'json']);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($report['device_assignments']['unassigned_devices'])->toBe(1)
        ->and($report['device_assignments']['ambiguously_assigned_devices'])->toBe(0)
        ->and($report['device_assignments']['future_assignment_rows'])->toBe(2)
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'device_active_site_assignment_conflict')['count'])
        ->toBe(0)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'device_assignment_site_target_missing')['count'])
        ->toBe(1)
        ->and(collect($report['orphan_checks'])->firstWhere('check', 'device_assignment_unknown_target_type')['count'])
        ->toBe(1)
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain('secret-invalid-assignment-type');
});

it('rejects mutating pragma statements in the outer-transaction query guard', function () {
    $command = app(AuditItSecuritySingleTenantData::class);
    $classifier = new ReflectionMethod($command, 'isAllowedAuditQuery');
    $readOnly = new ReflectionMethod($command, 'readOnly');

    foreach ([
        'PRAGMA user_version = 9173',
        'PRAGMA table_info("users"); PRAGMA user_version = 9173',
        'SELECT 1; PRAGMA user_version = 9173',
        'PRAGMA table_info = 9173',
    ] as $unsafeQuery) {
        expect($classifier->invoke($command, $unsafeQuery))->toBeFalse();
    }

    $connection = DB::connection();
    $isSqlite = $connection->getDriverName() === 'sqlite';
    $before = $isSqlite ? (int) DB::selectOne('PRAGMA user_version')->user_version : null;
    $unsafeStatement = $isSqlite
        ? 'PRAGMA user_version = 9173'
        : 'UPDATE users SET name = name WHERE 1 = 0';

    expect(fn () => $readOnly->invoke(
        $command,
        $connection,
        fn (): bool => DB::statement($unsafeStatement),
    ))->toThrow(RuntimeException::class, 'The audit attempted a non-read query.');

    if ($isSqlite) {
        expect((int) DB::selectOne('PRAGMA user_version')->user_version)->toBe($before);
    }
});

it('marks unavailable tables and columns explicitly instead of reporting a false zero', function () {
    $connectionName = 'audit_missing_schema';
    $originalConnection = config("database.connections.{$connectionName}");
    config()->set("database.connections.{$connectionName}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    try {
        $schema = DB::connection($connectionName)->getSchemaBuilder();
        $schema->create('it_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
        });

        $exit = Artisan::call('it-security:audit-single-tenant-data', [
            '--connection' => $connectionName,
            '--format' => 'json',
        ]);
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        DB::purge($connectionName);
        config()->set("database.connections.{$connectionName}", $originalConnection);
    }

    expect($exit)->toBe(0)
        ->and(collect($report['global_key_checks'])->firstWhere('key', 'kb_slug')['status'])->toBe('not_available')
        ->and(collect($report['provenance_checks'])->firstWhere('check', 'ticket_team_legacy_id_mismatch')['status'])
        ->toBe('not_available');
});

it('fails closed for an unavailable audit connection without exposing connection details', function () {
    $exit = Artisan::call('it-security:audit-single-tenant-data', [
        '--connection' => 'not-configured-for-audit',
        '--format' => 'json',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Audit could not start safely.')
        ->not->toContain('not-configured-for-audit')
        ->not->toContain('password');
});

/** @return array<string, int> */
function auditTableCounts(): array
{
    return collect([
        'it_tickets',
        'devices',
        'device_assignments',
        'integration_site_configs',
        'integrations',
        'it_ticket_links',
    ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
}

/** @return list<string> */
function auditReportKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($child)) {
            array_push($keys, ...auditReportKeys($child));
        }
    }

    return array_values(array_unique($keys));
}

function auditColumnHasGlobalUniqueIndex(string $table, string $column): bool
{
    return collect(Schema::getIndexes($table))->contains(function (array $index) use ($column): bool {
        $columns = array_map('strtolower', array_values($index['columns'] ?? []));

        return (bool) ($index['unique'] ?? false) && $columns === [strtolower($column)];
    });
}

/** @param array<string, mixed> $values
 * @return array<string, mixed>
 */
function auditFixtureRow(string $table, array $values): array
{
    return array_intersect_key($values, array_flip(Schema::getColumnListing($table)));
}

function auditColumnHasForeignKey(string $table, string $column): bool
{
    return collect(Schema::getForeignKeys($table))->contains(
        fn (array $foreignKey): bool => in_array(strtolower($column), array_map(
            'strtolower',
            array_values($foreignKey['columns'] ?? []),
        ), true),
    );
}

/** @return list<string> */
function auditTenantLeadingIndexNames(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->filter(fn (array $index): bool => strtolower((string) ($index['columns'][0] ?? '')) === 'tenant_id')
        ->pluck('name')
        ->map(fn (mixed $name): string => (string) $name)
        ->sort()
        ->values()
        ->all();
}

function insertAuditDevice(string $uid, int $legacyId): int
{
    return DB::table('devices')->insertGetId([
        'tenant_id' => $legacyId,
        'device_uid' => $uid,
        'name' => 'Private audit device',
        'domain' => 'it_infrastructure',
        'category' => 'network',
        'status' => 'active',
        'health_status' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createSingleTenantAuditTestSchema(): void
{
    if (Schema::hasTable('users')) {
        return;
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->unsignedBigInteger('organization_id')->nullable();
    });
    Schema::create('sites', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->string('name');
    });
    Schema::create('site_rooms', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
    });
    Schema::create('location_hardware', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
    });
    Schema::create('clients', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('organization_id')->nullable();
    });
    Schema::create('assets', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('home_site_id')->nullable();
        $table->unsignedBigInteger('client_id')->nullable();
        $table->unsignedBigInteger('organization_id')->nullable();
    });
    Schema::create('hr_employee_profiles', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('primary_site_id')->nullable();
        $table->unsignedBigInteger('tenant_id')->nullable();
        $table->unsignedBigInteger('organization_id')->nullable();
    });
    Schema::create('control_room_alerts', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('it_teams', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->unique(['tenant_id', 'name'], 'it_teams_tenant_name_uq');
    });
    Schema::create('it_tickets', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('reference')->nullable();
        $table->string('title');
        $table->unsignedBigInteger('requester_user_id');
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('category');
        $table->string('priority');
        $table->string('status');
        $table->string('work_type');
        $table->timestamps();
        $table->unique(['tenant_id', 'reference'], 'it_tickets_tenant_reference_uq');
        $table->index(['tenant_id', 'status'], 'it_tickets_tenant_status_idx');
    });
    Schema::create('devices', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('device_uid')->unique();
        $table->string('name');
        $table->string('domain');
        $table->string('category');
        $table->string('status');
        $table->string('health_status');
        $table->timestamps();
        $table->softDeletes();
        $table->index(['tenant_id', 'domain', 'status'], 'devices_tenant_domain_status_idx');
    });
    Schema::create('device_assignments', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('device_id');
        $table->string('assignable_type');
        $table->unsignedBigInteger('assignable_id');
        $table->string('assignment_type');
        $table->timestamp('assigned_at');
        $table->timestamp('released_at')->nullable();
        $table->timestamps();
    });
    Schema::create('it_ticket_links', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->unsignedBigInteger('ticket_id');
        $table->string('relationship');
        $table->string('linkable_type');
        $table->unsignedBigInteger('linkable_id');
        $table->timestamps();
    });
    Schema::create('integrations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('provider');
        $table->unique(['tenant_id', 'provider']);
    });
    Schema::create('integration_site_configs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->unsignedBigInteger('site_id');
        $table->string('provider');
        $table->string('status');
        $table->boolean('is_active');
        $table->timestamps();
    });

    foreach (auditIdentityTableDefinitions() as $definition) {
        Schema::create($definition['table'], function (Blueprint $table) use ($definition): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            foreach ($definition['columns'] as $column) {
                if ($column === 'requester_user_id') {
                    $table->unsignedBigInteger($column)->nullable();
                } elseif ($column === 'site_id') {
                    $table->unsignedBigInteger($column)->nullable();
                } else {
                    $table->string($column)->nullable();
                }
            }
        });
    }
}

/** @return list<array{table: string, columns: list<string>}> */
function auditIdentityTableDefinitions(): array
{
    return [
        ['table' => 'it_sla_policies', 'columns' => ['priority']],
        ['table' => 'it_kb_articles', 'columns' => ['slug']],
        ['table' => 'it_mailbox_connections', 'columns' => ['provider']],
        ['table' => 'it_queues', 'columns' => ['key']],
        ['table' => 'it_services', 'columns' => ['key']],
        ['table' => 'it_catalog_items', 'columns' => ['slug']],
        ['table' => 'it_catalog_submissions', 'columns' => ['requester_user_id', 'idempotency_key']],
        ['table' => 'it_provisioning_workflows', 'columns' => ['source_event_key']],
        ['table' => 'monitoring_collectors', 'columns' => ['collector_uuid', 'site_id']],
        ['table' => 'monitoring_profiles', 'columns' => ['name']],
        ['table' => 'device_groups', 'columns' => ['name']],
        ['table' => 'integration_events', 'columns' => ['provider', 'source_event_id', 'site_id']],
        ['table' => 'queclink_presets', 'columns' => ['slug']],
    ];
}
