<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function singleApplicationGlobalIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_21_160000_enforce_single_application_global_identities.php',
    );
}

function withSingleApplicationIdentityDatabase(Closure $callback): void
{
    $connection = 'single_application_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-global-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary identity migration database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('enforces the remaining application global identities', function (string $table, string $index): void {
    expect(Schema::hasIndex($table, $index))->toBeTrue();
})->with([
    'ticket reference' => ['it_tickets', 'it_tickets_tenant_reference_uq'],
    'SLA priority' => ['it_sla_policies', 'it_sla_policies_priority_uq'],
    'knowledge slug' => ['it_kb_articles', 'it_kb_articles_slug_uq'],
    'mailbox provider' => ['it_mailbox_connections', 'it_mailbox_connections_provider_uq'],
    'team name' => ['it_teams', 'it_teams_name_uq'],
    'queue key' => ['it_queues', 'it_queues_key_uq'],
    'service key' => ['it_services', 'it_services_key_uq'],
    'catalogue slug' => ['it_catalog_items', 'it_catalog_items_slug_uq'],
    'catalogue requester idempotency' => ['it_catalog_submissions', 'it_catalog_submissions_requester_idempotency_uq'],
    'provisioning source event' => ['it_provisioning_workflows', 'it_prov_workflows_source_event_uq'],
    'device group name' => ['device_groups', 'device_groups_name_uq'],
    'integration registry provider' => ['integrations', 'integrations_provider_uq'],
    'integration provider event' => ['integration_events', 'integration_events_provider_source_event_uq'],
    'Queclink preset slug' => ['queclink_presets', 'queclink_presets_slug_uq'],
]);

it('has no legacy boundary leading index in active IT or Security tables', function (): void {
    $tables = [
        'it_provisioning_requests',
        'it_tickets',
        'it_attachments',
        'it_ticket_comments',
        'it_ticket_events',
        'it_ticket_links',
        'it_ticket_approvals',
        'it_inbound_emails',
        'it_mailbox_connections',
        'it_sla_policies',
        'it_kb_articles',
        'it_teams',
        'it_queues',
        'it_services',
        'it_work_tasks',
        'it_catalog_items',
        'it_catalog_submissions',
        'it_service_identities',
        'it_api_requests',
        'it_problems',
        'it_changes',
        'it_major_incidents',
        'it_major_incident_updates',
        'it_provisioning_templates',
        'it_provisioning_workflows',
        'it_kb_interactions',
        'it_email_deliveries',
        'it_automation_runs',
        'devices',
        'device_groups',
        'integrations',
        'integration_tenant_secrets',
        'integration_site_configs',
        'integration_site_secrets',
        'integration_sync_logs',
        'integration_events',
        'integration_alerts',
        'site_rooms',
        'location_hardware',
        'queclink_devices',
        'queclink_raw_frames',
        'queclink_audit_events',
        'queclink_presets',
    ];

    $violations = [];
    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === 'tenant_id') {
                $violations[] = $table.'.'.$index['name'];
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the ticket reference constraint recognizable to workers during a rolling deploy', function (): void {
    $indexes = collect(Schema::getIndexes('it_tickets'))->keyBy('name');

    expect($indexes)->toHaveKey('it_tickets_tenant_reference_uq')
        ->and($indexes['it_tickets_tenant_reference_uq']['columns'])->toBe(['reference'])
        ->and($indexes['it_tickets_tenant_reference_uq']['unique'])->toBeTrue()
        ->and($indexes)->not->toHaveKey('it_tickets_reference_uq');
});

it('restores original composite index columns on rollback', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        Schema::create('it_tickets', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id');
            $blueprint->string('reference')->nullable();
            $blueprint->unique(
                ['tenant_id', 'reference'],
                'it_tickets_tenant_reference_uq',
            );
        });
        Schema::create('queclink_raw_frames', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id');
            $blueprint->timestamp('created_at')->nullable();
            $blueprint->index(
                ['tenant_id', 'created_at'],
                'queclink_raw_frames_tenant_id_created_at_index',
            );
        });

        $migration = singleApplicationGlobalIdentityMigration();
        $migration->up();

        $ticketIndex = collect(Schema::getIndexes('it_tickets'))
            ->firstWhere('name', 'it_tickets_tenant_reference_uq');
        expect($ticketIndex['columns'] ?? null)->toBe(['reference']);

        $migration->down();

        $ticketIndex = collect(Schema::getIndexes('it_tickets'))
            ->firstWhere('name', 'it_tickets_tenant_reference_uq');
        $rawFrameIndex = collect(Schema::getIndexes('queclink_raw_frames'))
            ->firstWhere('name', 'queclink_raw_frames_tenant_id_created_at_index');

        expect($ticketIndex['columns'] ?? null)->toBe(['tenant_id', 'reference'])
            ->and($rawFrameIndex['columns'] ?? null)->toBe(['tenant_id', 'created_at']);
    });
});

it('fails before schema mutation when a global application identity collides', function (
    string $label,
    string $table,
    array $columns,
    string $oldIndex,
    string $newIndex,
): void {
    withSingleApplicationIdentityDatabase(function () use (
        $label,
        $table,
        $columns,
        $oldIndex,
        $newIndex,
    ): void {
        Schema::create($table, function (Blueprint $blueprint) use ($columns, $oldIndex): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id');
            foreach ($columns as $column => $value) {
                if ($column === 'requester_user_id') {
                    $blueprint->unsignedBigInteger($column)->nullable();
                } else {
                    $blueprint->string($column)->nullable();
                }
            }
            $blueprint->unique(['tenant_id', ...array_keys($columns)], $oldIndex);
        });

        foreach ([11, 22] as $legacyValue) {
            DB::table($table)->insert([
                'tenant_id' => $legacyValue,
                ...$columns,
            ]);
        }

        expect(fn () => singleApplicationGlobalIdentityMigration()->up())
            ->toThrow(RuntimeException::class, $label);
        $candidate = collect(Schema::getIndexes($table))->firstWhere('name', $newIndex);
        if ($newIndex === $oldIndex) {
            expect($candidate['columns'] ?? null)->toBe(['tenant_id', ...array_keys($columns)]);
        } else {
            expect($candidate)->toBeNull();
        }
    });
})->with([
    'ticket reference' => ['ticket reference', 'it_tickets', ['reference' => 'IT-900001'], 'it_tickets_tenant_reference_uq', 'it_tickets_tenant_reference_uq'],
    'SLA priority' => ['SLA priority', 'it_sla_policies', ['priority' => 'urgent'], 'it_sla_policies_tenant_priority_uq', 'it_sla_policies_priority_uq'],
    'knowledge article slug' => ['knowledge article slug', 'it_kb_articles', ['slug' => 'vpn-help'], 'it_kb_articles_tenant_slug_uq', 'it_kb_articles_slug_uq'],
    'mailbox provider' => ['mailbox provider', 'it_mailbox_connections', ['provider' => 'microsoft'], 'it_mailbox_connections_tenant_id_provider_unique', 'it_mailbox_connections_provider_uq'],
    'team name' => ['IT team name', 'it_teams', ['name' => 'Service Desk'], 'it_teams_tenant_name_uq', 'it_teams_name_uq'],
    'queue key' => ['IT queue key', 'it_queues', ['key' => 'service-desk'], 'it_queues_tenant_key_uq', 'it_queues_key_uq'],
    'service key' => ['IT service key', 'it_services', ['key' => 'network'], 'it_services_tenant_key_uq', 'it_services_key_uq'],
    'catalogue slug' => ['catalogue item slug', 'it_catalog_items', ['slug' => 'new-laptop'], 'it_catalog_items_tenant_slug_uq', 'it_catalog_items_slug_uq'],
    'catalogue idempotency' => [
        'catalogue requester idempotency',
        'it_catalog_submissions',
        ['requester_user_id' => 7, 'idempotency_key' => 'submit-once'],
        'it_catalog_submissions_idempotency_uq',
        'it_catalog_submissions_requester_idempotency_uq',
    ],
    'provisioning source event' => [
        'provisioning source event',
        'it_provisioning_workflows',
        ['source_event_key' => 'employee:7:joiner'],
        'it_prov_workflows_tenant_event_uq',
        'it_prov_workflows_source_event_uq',
    ],
    'Device Group name' => ['Device Group name', 'device_groups', ['name' => 'Core switches'], 'dev_groups_tenant_name_unique', 'device_groups_name_uq'],
    'integration registry provider' => ['integration registry provider', 'integrations', ['provider' => 'unifi'], 'integrations_tenant_id_provider_unique', 'integrations_provider_uq'],
    'integration event' => [
        'integration provider event',
        'integration_events',
        ['provider' => 'unifi', 'source_event_id' => 'evt-7'],
        'integration_events_tenant_provider_source_event_unique',
        'integration_events_provider_source_event_uq',
    ],
    'Queclink preset slug' => ['Queclink preset slug', 'queclink_presets', ['slug' => 'safe-tracking'], 'queclink_presets_tenant_id_slug_unique', 'queclink_presets_slug_uq'],
]);
