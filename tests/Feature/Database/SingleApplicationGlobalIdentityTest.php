<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function singleApplicationGlobalIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_21_160000_enforce_single_application_global_identities.php',
    );
}

function calendarSyncSingleApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_22_122000_enforce_calendar_sync_single_application_identity.php',
    );
}

function calendarSyncLegacyIndexCleanupMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_22_123000_remove_calendar_sync_legacy_leading_indexes.php',
    );
}

function onboardingSingleApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_23_000005_enforce_onboarding_single_application_identity.php',
    );
}

function recruitmentSingleApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_23_000006_enforce_recruitment_single_application_identity.php',
    );
}

function peopleConfigurationSingleApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000001_enforce_people_configuration_single_application_identity.php',
    );
}

function createPeopleConfigurationIdentitySchema(): void
{
    Schema::create('hr_departments', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('sort_order')->default(0);
        $table->unique(['tenant_id', 'name']);
    });
    Schema::create('hr_positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('code');
        $table->string('department')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unique(['tenant_id', 'code']);
        $table->index(['tenant_id', 'is_active']);
        $table->index(['tenant_id', 'department']);
    });
}

function createRecruitmentIdentitySchema(): void
{
    Schema::create('hr_candidates', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('status');
        $table->timestamp('current_stage_entered_at')->nullable();
        $table->index(['tenant_id', 'status']);
    });
    Schema::create('hr_applications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('position_role')->nullable();
        $table->unsignedBigInteger('target_site_id')->nullable();
        $table->string('status');
        $table->index(['tenant_id', 'position_role']);
    });
    Schema::create('hr_interview_kits', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('name');
        $table->string('role')->nullable();
        $table->boolean('is_active')->default(true);
        $table->index(['tenant_id', 'is_active']);
        $table->index(['tenant_id', 'role', 'is_active'], 'hr_int_kits_tenant_role_active_idx');
    });
    Schema::create('hr_job_requisitions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('slug');
        $table->string('status');
        $table->timestamp('published_at')->nullable();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unique(['tenant_id', 'slug']);
        $table->index(['tenant_id', 'status', 'published_at'], 'hr_job_req_tenant_status_pub_idx');
    });
    Schema::create('hr_candidate_email_templates', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('name');
        $table->index(['tenant_id', 'name']);
    });
    Schema::create('hr_candidate_documents', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
    });
    Schema::create('hr_talent_pool', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
    });
    Schema::create('hr_offers', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('application_id')->index();
    });
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
    'calendar provider connection' => ['calendar_sync_connections', 'calendar_sync_connections_provider_uq'],
    'onboarding template role and Site type' => ['hr_onboarding_templates', 'hr_onboarding_templates_role_site_uq'],
    'requisition slug' => ['hr_job_requisitions', 'hr_job_req_slug_uq'],
    'offer application' => ['hr_offers', 'hr_offers_application_uq'],
    'interview kit name' => ['hr_interview_kits', 'hr_interview_kits_name_uq'],
    'candidate email template name' => ['hr_candidate_email_templates', 'hr_candidate_email_templates_name_uq'],
    'department name' => ['hr_departments', 'hr_departments_name_uq'],
    'position code' => ['hr_positions', 'hr_positions_code_uq'],
    'custom field key' => ['hr_custom_field_definitions', 'hr_custom_fields_field_key_uq'],
    'policy slug' => ['hr_policies', 'hr_policies_slug_uq'],
    'competency name' => ['hr_competencies', 'hr_competencies_name_uq'],
    'feedback template name' => ['hr_feedback_templates', 'hr_feedback_templates_name_uq'],
    'benefit plan name' => ['hr_benefit_plans', 'hr_benefit_plans_name_uq'],
    'employee benefit plan' => ['hr_benefit_enrollments', 'hr_benefit_enrollments_profile_plan_uq'],
    'salary band role name and effective date' => ['hr_salary_bands', 'hr_salary_bands_role_name_effective_uq'],
    'compensation review employee' => ['hr_compensation_review_items', 'hr_compensation_review_items_review_profile_uq'],
    'expense claim number' => ['hr_expense_claims', 'hr_expense_claims_claim_number_uq'],
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
        'calendar_sync_connections',
        'calendar_sync_mappings',
        'calendar_sync_event_links',
        'calendar_sync_busy_blocks',
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

it('fails before calendar schema mutation when the application provider identity collides', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        Schema::create('calendar_sync_connections', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id')->index();
            $blueprint->string('provider');
            $blueprint->unique(['tenant_id', 'provider']);
        });
        Schema::create('calendar_sync_mappings', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('site_id');
            $blueprint->boolean('is_active')->default(true);
        });

        DB::table('calendar_sync_connections')->insert([
            ['tenant_id' => 11, 'provider' => 'google'],
            ['tenant_id' => 22, 'provider' => 'google'],
        ]);

        $before = collect(Schema::getIndexes('calendar_sync_connections'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();

        expect(fn () => calendarSyncSingleApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'calendar provider connection');

        $after = collect(Schema::getIndexes('calendar_sync_connections'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();

        expect($after)->toBe($before)
            ->and(Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_provider_uq'))
            ->toBeFalse();
    });
});

it('fails before onboarding template schema mutation when application identity collides', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        Schema::create('hr_onboarding_templates', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id')->index();
            $blueprint->string('role');
            $blueprint->string('site_type');
            $blueprint->boolean('is_active')->default(true);
            $blueprint->unique(
                ['tenant_id', 'role', 'site_type'],
                'hr_onboarding_templates_tenant_id_role_site_type_unique',
            );
        });

        DB::table('hr_onboarding_templates')->insert([
            ['tenant_id' => 11, 'role' => 'support_worker', 'site_type' => 'house'],
            ['tenant_id' => 22, 'role' => 'support_worker', 'site_type' => 'house'],
        ]);

        $before = collect(Schema::getIndexes('hr_onboarding_templates'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();

        expect(fn () => onboardingSingleApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'onboarding-template identity');

        $after = collect(Schema::getIndexes('hr_onboarding_templates'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();

        expect($after)->toBe($before)
            ->and(Schema::hasIndex('hr_onboarding_templates', 'hr_onboarding_templates_role_site_uq'))
            ->toBeFalse();
    });
});

it('enforces and rolls back onboarding template application identity', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        Schema::create('hr_onboarding_templates', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id')->index();
            $blueprint->string('role');
            $blueprint->string('site_type');
            $blueprint->boolean('is_active')->default(true);
            $blueprint->unique(
                ['tenant_id', 'role', 'site_type'],
                'hr_onboarding_templates_tenant_id_role_site_type_unique',
            );
        });

        DB::table('hr_onboarding_templates')->insert([
            'tenant_id' => 11,
            'role' => 'support_worker',
            'site_type' => 'house',
        ]);

        $migration = onboardingSingleApplicationIdentityMigration();
        $migration->up();

        $global = collect(Schema::getIndexes('hr_onboarding_templates'))
            ->firstWhere('name', 'hr_onboarding_templates_role_site_uq');
        expect($global['columns'] ?? null)->toBe(['role', 'site_type'])
            ->and($global['unique'] ?? null)->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_onboarding_templates',
                'hr_onboarding_templates_tenant_id_role_site_type_unique',
            ))->toBeFalse();

        expect(fn () => DB::table('hr_onboarding_templates')->insert([
            'tenant_id' => 22,
            'role' => 'support_worker',
            'site_type' => 'house',
        ]))->toThrow(QueryException::class);

        $migration->down();

        $legacy = collect(Schema::getIndexes('hr_onboarding_templates'))
            ->firstWhere('name', 'hr_onboarding_templates_tenant_id_role_site_type_unique');
        expect($legacy['columns'] ?? null)->toBe(['tenant_id', 'role', 'site_type'])
            ->and($legacy['unique'] ?? null)->toBeTrue()
            ->and(Schema::hasIndex('hr_onboarding_templates', 'hr_onboarding_templates_role_site_uq'))
            ->toBeFalse();
    });
});

it('fails before recruitment schema mutation when an application identity collides', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        createRecruitmentIdentitySchema();
        DB::table('hr_job_requisitions')->insert([
            ['tenant_id' => 11, 'slug' => 'support-worker', 'status' => 'draft'],
            ['tenant_id' => 22, 'slug' => 'support-worker', 'status' => 'draft'],
        ]);

        $before = collect(Schema::getIndexes('hr_job_requisitions'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();

        expect(fn () => recruitmentSingleApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'job requisition slug');

        $after = collect(Schema::getIndexes('hr_job_requisitions'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();
        expect($after)->toBe($before)
            ->and(Schema::hasIndex('hr_job_requisitions', 'hr_job_req_slug_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_offers', 'hr_offers_application_uq'))->toBeFalse();
    });
});

it('enforces and rolls back recruitment application identities and indexes', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        createRecruitmentIdentitySchema();
        DB::table('hr_job_requisitions')->insert([
            'tenant_id' => 11,
            'slug' => 'support-worker',
            'status' => 'draft',
        ]);
        DB::table('hr_offers')->insert(['application_id' => 91]);
        DB::table('hr_interview_kits')->insert([
            'tenant_id' => 11,
            'name' => 'Support interview',
            'role' => 'support_worker',
            'is_active' => true,
        ]);
        DB::table('hr_candidate_email_templates')->insert([
            'tenant_id' => 11,
            'name' => 'Application received',
        ]);

        $migration = recruitmentSingleApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_job_requisitions', 'hr_job_req_slug_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_offers', 'hr_offers_application_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_interview_kits', 'hr_interview_kits_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_candidate_email_templates', 'hr_candidate_email_templates_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_job_requisitions', 'hr_job_requisitions_tenant_id_slug_unique'))->toBeFalse()
            ->and(Schema::hasIndex('hr_interview_kits', 'hr_int_kits_tenant_role_active_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_candidate_documents', 'hr_candidate_documents_tenant_id_index'))->toBeFalse();

        expect(fn () => DB::table('hr_offers')->insert(['application_id' => 91]))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('hr_candidate_email_templates')->insert([
                'tenant_id' => 22,
                'name' => 'Application received',
            ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_job_requisitions', 'hr_job_req_slug_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_offers', 'hr_offers_application_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_job_requisitions', 'hr_job_requisitions_tenant_id_slug_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_interview_kits', 'hr_int_kits_tenant_role_active_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_candidate_documents', 'hr_candidate_documents_tenant_id_index'))->toBeTrue();
    });
});

it('fails before people configuration schema mutation when :identity collides', function (
    string $identity,
    string $table,
    array $rows,
): void {
    withSingleApplicationIdentityDatabase(function () use ($identity, $table, $rows): void {
        createPeopleConfigurationIdentitySchema();
        DB::table($table)->insert($rows);

        $before = [
            'hr_departments' => collect(Schema::getIndexes('hr_departments'))
                ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
                ->all(),
            'hr_positions' => collect(Schema::getIndexes('hr_positions'))
                ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
                ->all(),
        ];

        expect(fn () => peopleConfigurationSingleApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, $identity);

        $after = [
            'hr_departments' => collect(Schema::getIndexes('hr_departments'))
                ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
                ->all(),
            'hr_positions' => collect(Schema::getIndexes('hr_positions'))
                ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
                ->all(),
        ];

        expect($after)->toBe($before)
            ->and(Schema::hasIndex('hr_departments', 'hr_departments_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_code_uq'))->toBeFalse();
    });
})->with([
    'department name' => [
        'department name',
        'hr_departments',
        [
            ['tenant_id' => 11, 'name' => 'Operations'],
            ['tenant_id' => 22, 'name' => 'Operations'],
        ],
    ],
    'position code' => [
        'position code',
        'hr_positions',
        [
            ['tenant_id' => 11, 'code' => 'SW', 'department' => 'Operations'],
            ['tenant_id' => 22, 'code' => 'SW', 'department' => 'Operations'],
        ],
    ],
]);

it('enforces and rolls back people configuration application identities and indexes', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        createPeopleConfigurationIdentitySchema();
        DB::table('hr_departments')->insert([
            'tenant_id' => 11,
            'name' => 'Operations',
        ]);
        DB::table('hr_positions')->insert([
            'tenant_id' => 11,
            'code' => 'SW',
            'department' => 'Operations',
        ]);

        $migration = peopleConfigurationSingleApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_departments', 'hr_departments_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_departments', 'hr_departments_active_sort_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_code_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_active_department_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_departments', 'hr_departments_tenant_id_name_unique'))->toBeFalse()
            ->and(Schema::hasIndex('hr_departments', 'hr_departments_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_code_unique'))->toBeFalse()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_is_active_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_department_index'))->toBeFalse();

        expect(fn () => DB::table('hr_departments')->insert([
            'tenant_id' => 22,
            'name' => 'Operations',
        ]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('hr_positions')->insert([
                'tenant_id' => 22,
                'code' => 'SW',
                'department' => 'Operations',
            ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_departments', 'hr_departments_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_departments', 'hr_departments_active_sort_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_code_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_active_department_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_departments', 'hr_departments_tenant_id_name_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_departments', 'hr_departments_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_code_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_is_active_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_positions', 'hr_positions_tenant_id_department_index'))->toBeTrue();
    });
});

it('fails before calendar schema mutation when one site has several active mappings', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        Schema::create('calendar_sync_connections', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id')->index();
            $blueprint->string('provider');
            $blueprint->unique(['tenant_id', 'provider']);
        });
        Schema::create('calendar_sync_mappings', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('site_id');
            $blueprint->string('provider');
            $blueprint->boolean('is_active')->default(true);
        });
        DB::table('calendar_sync_mappings')->insert([
            ['site_id' => 7, 'provider' => 'google', 'is_active' => true],
            ['site_id' => 7, 'provider' => 'microsoft', 'is_active' => true],
        ]);

        $before = collect(Schema::getIndexes('calendar_sync_connections'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();

        expect(fn () => calendarSyncSingleApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'active calendar mappings');

        $after = collect(Schema::getIndexes('calendar_sync_connections'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();
        expect($after)->toBe($before)
            ->and(Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_provider_uq'))
            ->toBeFalse();
    });
});

it('recovers safely when the stronger calendar identity was installed before cleanup failed', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        Schema::create('calendar_sync_connections', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id')->index();
            $blueprint->string('provider');
            $blueprint->unique(['tenant_id', 'provider']);
            $blueprint->unique('provider', 'calendar_sync_connections_provider_uq');
        });
        Schema::create('calendar_sync_mappings', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('site_id');
            $blueprint->boolean('is_active')->default(true);
        });
        DB::table('calendar_sync_connections')->insert([
            'tenant_id' => 11,
            'provider' => 'google',
        ]);

        calendarSyncSingleApplicationIdentityMigration()->up();

        $indexes = collect(Schema::getIndexes('calendar_sync_connections'))->keyBy('name');
        expect($indexes)->toHaveKey('calendar_sync_connections_provider_uq')
            ->and($indexes)->not->toHaveKey('calendar_sync_connections_tenant_id_provider_unique')
            ->and($indexes)->not->toHaveKey('calendar_sync_connections_tenant_id_index')
            ->and(DB::table('calendar_sync_connections')->count())->toBe(1);
    });
});

it('replaces and restores the exact calendar compatibility indexes without rewriting data', function (): void {
    withSingleApplicationIdentityDatabase(function (): void {
        Schema::create('calendar_sync_connections', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('tenant_id')->index();
            $blueprint->string('provider');
            $blueprint->unique(['tenant_id', 'provider']);
        });
        foreach (['calendar_sync_mappings', 'calendar_sync_event_links', 'calendar_sync_busy_blocks'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->unsignedBigInteger('tenant_id')->index();
                if ($table === 'calendar_sync_mappings') {
                    $blueprint->unsignedBigInteger('site_id');
                    $blueprint->boolean('is_active')->default(true);
                }
            });
        }
        DB::table('calendar_sync_connections')->insert([
            'tenant_id' => 11,
            'provider' => 'google',
        ]);

        $migration = calendarSyncSingleApplicationIdentityMigration();
        $migration->up();
        $cleanup = calendarSyncLegacyIndexCleanupMigration();
        $cleanup->up();

        expect(Schema::hasIndex('calendar_sync_connections', 'calendar_sync_connections_provider_uq'))
            ->toBeTrue();
        expect(fn () => DB::table('calendar_sync_connections')->insert([
            'tenant_id' => 22,
            'provider' => 'google',
        ]))->toThrow(QueryException::class);
        foreach ([
            'calendar_sync_connections',
            'calendar_sync_mappings',
            'calendar_sync_event_links',
            'calendar_sync_busy_blocks',
        ] as $table) {
            $leadingColumns = collect(Schema::getIndexes($table))
                ->map(fn (array $index) => $index['columns'][0] ?? null)
                ->filter()
                ->values()
                ->all();
            expect($leadingColumns)->not->toContain('tenant_id');
        }

        $cleanup->down();
        $migration->down();

        $connectionIndexes = collect(Schema::getIndexes('calendar_sync_connections'))->keyBy('name');
        expect($connectionIndexes)->not->toHaveKey('calendar_sync_connections_provider_uq')
            ->and($connectionIndexes['calendar_sync_connections_tenant_id_provider_unique']['columns'] ?? null)
            ->toBe(['tenant_id', 'provider'])
            ->and($connectionIndexes['calendar_sync_connections_tenant_id_index']['columns'] ?? null)
            ->toBe(['tenant_id'])
            ->and(DB::table('calendar_sync_connections')->count())->toBe(1);

        foreach (['calendar_sync_mappings', 'calendar_sync_event_links', 'calendar_sync_busy_blocks'] as $table) {
            expect(Schema::hasIndex($table, $table.'_tenant_id_index'))->toBeTrue();
        }
    });
});
