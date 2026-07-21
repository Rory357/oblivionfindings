<?php

it('ratchets active IT Security Devices and Monitoring tenant behavior without claiming remediation complete', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $actual = itSecurityTenantDebtSnapshot($root);

    expect($actual)->toBe(itSecurityApprovedTenantDebt());
});

it('detects an injected tenant authorization shortcut in a new file', function () {
    $violations = itSecurityScanTenantSource(
        'app/Domain/It/Services/NewTenantShortcut.php',
        <<<'PHP'
            <?php
            $tenantId = $request->user()->tenant_id;
            return Ticket::forTenant($tenantId)->get();
            LegacyTicket::forTenantOrSystem($tenantId)->count();
            PHP,
    );

    expect(array_keys($violations))
        ->toContain('tenant_parameter')
        ->toContain('tenant_storage_or_usage')
        ->toContain('tenant_query_scope');
    expect($violations['tenant_query_scope'])->toHaveCount(2);
});

it('changes the debt fingerprint when equal-count tenant shortcut semantics change', function () {
    $before = itSecurityScanTenantSource(
        'app/Domain/It/Services/ExistingShortcut.php',
        <<<'PHP'
            <?php
            return Ticket::forTenant($tenantId)
                ->where('status', 'open')
                ->get();
            PHP,
    )['tenant_query_scope'];
    $replacement = itSecurityScanTenantSource(
        'app/Domain/It/Services/ExistingShortcut.php',
        <<<'PHP'
            <?php
            return Ticket::forTenant($tenantId)
                ->where('status', 'closed')
                ->delete();
            PHP,
    )['tenant_query_scope'];

    expect($before)->toHaveCount(1)
        ->and($replacement)->toHaveCount(1)
        ->and($replacement)->not->toBe($before)
        ->and(itSecurityTenantRuleFingerprint($replacement))
        ->not->toBe(itSecurityTenantRuleFingerprint($before));
});

it('keeps legacy storage compatibility narrow and rejects the same field in a new model', function () {
    $storageDeclaration = <<<'PHP'
        <?php
        class Monitor {
            protected $fillable = [
                'tenant_id',
            ];
        }
        PHP;

    expect(itSecurityScanTenantSource('app/Domain/Monitoring/Models/Monitor.php', $storageDeclaration))
        ->not->toHaveKey('tenant_storage_or_usage')
        ->and(itSecurityScanTenantSource('app/Domain/Monitoring/Models/NewMonitor.php', $storageDeclaration))
        ->toHaveKey('tenant_storage_or_usage');
});

it('includes Security and Devices compatibility models that live outside the domain folder', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $scopedFiles = itSecurityScopedFiles($root);

    expect($scopedFiles)
        ->toContain($root.'/app/Models/SiteRoom.php')
        ->toContain($root.'/app/Models/LocationHardware.php');
});

it('rejects a new tenant partition migration while excluding exact historical migrations', function () {
    $source = <<<'PHP'
        <?php
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->index();
        });
        PHP;

    expect(itSecurityScanTenantMigration(
        'database/migrations/2026_07_22_000001_add_tenant_partition_to_it_tickets.php',
        $source,
    ))->toBeTrue()
        ->and(itSecurityScanTenantMigration(
            'database/migrations/2026_07_02_100001_create_it_provisioning_tables.php',
            $source,
        ))->toBeFalse();

    $removal = <<<'PHP'
        <?php
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->dropIndex('it_tickets_tenant_status_idx');
            $table->dropColumn('tenant_id');
        });
        PHP;
    expect(itSecurityScanTenantMigration(
        'database/migrations/2026_07_22_000002_remove_legacy_tenant_partition.php',
        $removal,
    ))->toBeFalse();

    $additionalPartitionForms = [
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignUuid('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignUlid('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->unsignedSmallInteger('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->tinyInteger('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->char('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->id('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->morphs('tenant'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->nullableMorphs('tenant'));",
        "DB::statement('ALTER TABLE it_tickets ADD COLUMN tenant_id BIGINT')",
        "Schema::table('monitor_observations', fn (Blueprint \$table) => \$table->uuid('tenant_id'));",
        'Schema::table("device_assignments", fn (Blueprint $table) => $table->foreignId("tenant_id"));',
        "Schema::table('site_rooms', fn (Blueprint \$table) => \$table->foreignId('tenant_id'));",
        "Schema::table('location_hardware', fn (Blueprint \$table) => \$table->uuid('tenant_id'));",
        "DB::statement('ALTER TABLE site_rooms ADD COLUMN tenant_id BIGINT')",
    ];
    foreach ($additionalPartitionForms as $index => $partitionSource) {
        expect(itSecurityScanTenantMigration(
            "database/migrations/2026_07_22_0001{$index}_add_partition_variant.php",
            "<?php\n{$partitionSource}",
        ))->toBeTrue();
    }

    expect(itSecurityScanTenantMigration(
        'database/migrations/2026_07_22_000099_drop_raw_tenant_partition.php',
        "<?php\nDB::statement('ALTER TABLE it_tickets DROP COLUMN tenant_id')",
    ))->toBeFalse();
});

/** @return list<string> */
function itSecurityTenantDebtSnapshot(string $root): array
{
    $snapshot = [];

    foreach (itSecurityScopedFiles($root) as $absolutePath) {
        $relativePath = ltrim(substr($absolutePath, strlen($root)), '/');
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read scoped boundary file {$relativePath}.");
        }

        foreach (itSecurityScanTenantSource($relativePath, $contents) as $rule => $matches) {
            $snapshot[] = implode('|', [
                $relativePath,
                $rule,
                count($matches),
                itSecurityTenantRuleFingerprint($matches),
            ]);
        }
    }

    foreach (itSecurityMigrationFiles($root) as $absolutePath) {
        $relativePath = ltrim(substr($absolutePath, strlen($root)), '/');
        $contents = file_get_contents($absolutePath);
        if ($contents !== false && itSecurityScanTenantMigration($relativePath, $contents)) {
            $snapshot[] = $relativePath.'|new_tenant_partition_migration|1|'.substr(hash('sha256', 'tenant_id'), 0, 16);
        }
    }

    sort($snapshot, SORT_STRING);

    return $snapshot;
}

/**
 * Return a line-independent normalized statement-context snapshot grouped by
 * rule. Existing active debt is ratcheted by exact path + rule + count +
 * bounded context hash; Task 9 must reduce the approved snapshot to empty.
 *
 * @return array<string, list<string>>
 */
function itSecurityScanTenantSource(string $relativePath, string $contents): array
{
    $patterns = [
        'tenant_query_scope' => '/\b(?:scopeForTenant(?:OrSystem)?|forTenant(?:OrSystem)?)\b/u',
        'tenant_resolver' => '/\b(?:Resolves(?:Hr|Device)?Tenant|resolve(?:Hr|Device)?TenantId(?:ForUser)?|resolveTenantId)\b/u',
        'tenant_parameter' => '/\btenantId\b/u',
        'tenant_storage_or_usage' => '/\btenant_id\b/u',
        'organisation_comparison' => '/(?:->|[\'\"]|\bwhere\s*\()[^\r\n]{0,80}\borganization_id\b/iu',
        'all_tenant_sites_bypass' => '/\bcanViewAllTenantSites\b/u',
        'tenant_matcher' => '/\b[A-Za-z][A-Za-z0-9_]*MatchesTenant\b/u',
        'tenant_secret_contract' => '/\b(?:tenantSecret|IntegrationTenantSecret)\b/u',
        'tenant_product_copy' => '/\b(?:same|foreign|cross|other)\s+tenant\b|\btenant[- ](?:wide|scoped|scope)\b|\bmulti[- ]tenant\b/iu',
    ];
    $violations = [];

    foreach ($patterns as $rule => $pattern) {
        preg_match_all($pattern, $contents, $rawMatches, PREG_OFFSET_CAPTURE);
        $tokens = [];

        foreach ($rawMatches[0] ?? [] as [$token, $offset]) {
            if ($rule === 'tenant_storage_or_usage'
                && itSecurityIsAllowedLegacyStorageOccurrence($relativePath, $contents, (int) $offset)
            ) {
                continue;
            }

            $tokens[] = itSecurityNormalizedTenantTokenContext(
                $contents,
                (int) $offset,
                (string) $token,
            );
        }

        if ($tokens !== []) {
            sort($tokens, SORT_STRING);
            $violations[$rule] = $tokens;
        }
    }

    ksort($violations, SORT_STRING);

    return $violations;
}

/** @param list<string> $matches */
function itSecurityTenantRuleFingerprint(array $matches): string
{
    return substr(hash('sha256', json_encode($matches, JSON_THROW_ON_ERROR)), 0, 16);
}

/**
 * Fingerprint the bounded logical statement around a token. All whitespace is
 * removed so formatting and line movement do not churn the baseline, while an
 * equal-count semantic replacement in the same file produces a different hash.
 */
function itSecurityNormalizedTenantTokenContext(string $contents, int $offset, string $token): string
{
    $statementStart = 0;
    $prefix = substr($contents, 0, $offset);
    foreach (["\n\n", ';', '{', '}'] as $delimiter) {
        $position = strrpos($prefix, $delimiter);
        if ($position !== false) {
            $statementStart = max($statementStart, $position + strlen($delimiter));
        }
    }

    $tokenEnd = $offset + strlen($token);
    $statementEnd = strlen($contents);
    foreach (["\n\n", ';', '{', '}'] as $delimiter) {
        $position = strpos($contents, $delimiter, $tokenEnd);
        if ($position !== false) {
            $statementEnd = min($statementEnd, $position + strlen($delimiter));
        }
    }

    $maximumContext = 640;
    if ($statementEnd - $statementStart > $maximumContext) {
        $half = intdiv($maximumContext, 2);
        $statementStart = max($statementStart, $offset - $half);
        $statementEnd = min($statementEnd, $tokenEnd + $half);
    }

    $statement = substr($contents, $statementStart, $statementEnd - $statementStart);
    $normalized = preg_replace('/\s+/u', '', $statement);
    if (! is_string($normalized) || $normalized === '') {
        $normalized = $token;
    }

    return strtolower($token).'@'.substr(hash('sha256', $normalized), 0, 24);
}

function itSecurityIsAllowedLegacyStorageOccurrence(string $relativePath, string $contents, int $offset): bool
{
    if (! in_array($relativePath, itSecurityLegacyStorageFiles(), true)) {
        return false;
    }

    $lineStart = strrpos(substr($contents, 0, $offset), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $offset);
    $line = trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart));
    if (preg_match('/^[\'\"]tenant_id[\'\"]\s*(?:=>\s*[\'\"](?:int|integer|string)[\'\"])?\s*,?$/', $line) !== 1) {
        return false;
    }

    $prefix = substr($contents, 0, $offset);
    $fillable = strrpos($prefix, 'protected $fillable = [');
    $casts = strrpos($prefix, 'protected $casts = [');
    $start = max($fillable === false ? -1 : $fillable, $casts === false ? -1 : $casts);
    $close = strrpos($prefix, '];');

    return $start >= 0 && ($close === false || $start > $close);
}

/** @return list<string> */
function itSecurityScopedFiles(string $root): array
{
    $files = [];
    foreach ([
        'app/Domain/It',
        'app/Domain/Monitoring',
        'app/Domain/SecurityDevices',
        'app/Http/Controllers/It',
        'app/Listeners/It',
        'resources/js/pages/it',
        'resources/js/pages/security-devices',
        'tests/Feature/It',
        'tests/Feature/Monitoring',
        'tests/Feature/SecurityDevices',
        'tests/Unit/Monitoring',
        'tests/Unit/SecurityDevices',
    ] as $directory) {
        $files = [...$files, ...itSecurityRecursiveFiles($root.'/'.$directory)];
    }

    foreach ([
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php',
        'app/Http/Controllers/Api/ItApiWorkItemController.php',
        'app/Http/Middleware/AuthenticateItServiceIdentity.php',
        'app/Http/Middleware/EnsureItApiAbility.php',
        'app/Http/Middleware/RecordItApiRequest.php',
        'app/Http/Controllers/Settings/ItMailboxOAuthController.php',
        'app/Http/Controllers/Settings/ItMailboxSettingsController.php',
        'app/Models/SiteRoom.php',
        'app/Models/LocationHardware.php',
        'docs/it-support-security-devices-completion-goal.md',
        'docs/it-support-service-api-v1.md',
        'docs/security-devices-restructure-plan.md',
        'docs/security-devices-next-session.md',
        'docs/superpowers/plans/2026-07-18-it-support-service-management-expansion.md',
        'docs/superpowers/plans/2026-07-21-native-monitoring-runtime.md',
        'docs/superpowers/specs/2026-07-18-it-support-native-monitoring-platform-design.md',
    ] as $relativePath) {
        $absolutePath = $root.'/'.$relativePath;
        if (is_file($absolutePath)) {
            $files[] = $absolutePath;
        }
    }

    foreach (['app/Models', 'app/Policies'] as $directory) {
        foreach (itSecurityRecursiveFiles($root.'/'.$directory) as $file) {
            $relativePath = ltrim(substr($file, strlen($root)), '/');
            if (preg_match('#^app/(?:Models|Policies)/It[^/]*\.php$#', $relativePath) === 1
                || preg_match('#^app/Models/(?:Integration|Queclink)/#', $relativePath) === 1
            ) {
                $files[] = $file;
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);

    return $files;
}

/** @return list<string> */
function itSecurityRecursiveFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'ts', 'tsx', 'md'], true)) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    return $files;
}

/** @return list<string> */
function itSecurityMigrationFiles(string $root): array
{
    return itSecurityRecursiveFiles($root.'/database/migrations');
}

function itSecurityScanTenantMigration(string $relativePath, string $contents): bool
{
    if (in_array($relativePath, itSecurityHistoricalTenantMigrations(), true)) {
        return false;
    }

    $targetsAuditedTables = preg_match(
        '/Schema::(?:create|table)\(\s*[\'\"](?:it_|monitor_|monitoring_|monitors|devices|device_|integration_|integrations|queclink_|site_rooms|location_hardware)/',
        $contents,
    ) === 1
        || preg_match(
            '/ALTER\s+TABLE\s+[`\'\"]?(?:it_|monitor_|monitoring_|monitors|devices|device_|integration_|integrations|queclink_|site_rooms|location_hardware)/i',
            $contents,
        ) === 1;
    $addsTenantColumn = preg_match(
        '/->(?:foreignId|foreignUuid|foreignUlid|unsignedBigInteger|bigInteger|unsignedInteger|integer|unsignedSmallInteger|smallInteger|unsignedTinyInteger|tinyInteger|uuid|ulid|string|char|id)\s*\(\s*[\'\"]tenant_id[\'\"]/',
        $contents,
    ) === 1
        || preg_match(
            '/->(?:morphs|nullableMorphs|uuidMorphs|nullableUuidMorphs|ulidMorphs|nullableUlidMorphs)\s*\(\s*[\'\"]tenant[\'\"]/',
            $contents,
        ) === 1
        || preg_match(
            '/->addColumn\s*\(\s*[\'\"][^\'\"]+[\'\"]\s*,\s*[\'\"]tenant_id[\'\"]/',
            $contents,
        ) === 1
        || preg_match(
            '/ALTER\s+TABLE\s+[`\'\"]?(?:it_|monitor_|monitoring_|monitors|devices|device_|integration_|integrations|queclink_|site_rooms|location_hardware)[A-Za-z0-9_`\'\"]*\s+ADD(?:\s+COLUMN)?\s+[`\'\"]?tenant_id\b/i',
            $contents,
        ) === 1;

    return $targetsAuditedTables && $addsTenantColumn;
}

/** @return list<string> */
function itSecurityLegacyStorageFiles(): array
{
    return [
        'app/Domain/Monitoring/Models/Monitor.php',
        'app/Domain/Monitoring/Models/MonitorObservation.php',
        'app/Domain/Monitoring/Models/MonitoringCollector.php',
        'app/Domain/Monitoring/Models/MonitoringProfile.php',
        'app/Domain/SecurityDevices/Models/Device.php',
        'app/Domain/SecurityDevices/Models/DeviceEvent.php',
        'app/Domain/SecurityDevices/Models/DeviceGroup.php',
        'app/Domain/SecurityDevices/Models/DeviceMaintenanceRecord.php',
        'app/Models/Integration/Integration.php',
        'app/Models/Integration/IntegrationAlert.php',
        'app/Models/Integration/IntegrationEvent.php',
        'app/Models/Integration/IntegrationSiteConfig.php',
        'app/Models/Integration/IntegrationSiteSecret.php',
        'app/Models/Integration/IntegrationSyncLog.php',
        'app/Models/Integration/IntegrationTenantSecret.php',
        'app/Models/SiteRoom.php',
        'app/Models/LocationHardware.php',
        'app/Models/Queclink/QueclinkAuditEvent.php',
        'app/Models/Queclink/QueclinkDevice.php',
        'app/Models/Queclink/QueclinkPendingCommand.php',
        'app/Models/Queclink/QueclinkPreset.php',
        'app/Models/Queclink/QueclinkRawFrame.php',
        'app/Models/ItApiRequest.php',
        'app/Models/ItAttachment.php',
        'app/Models/ItAutomationRun.php',
        'app/Models/ItCatalogItem.php',
        'app/Models/ItCatalogSubmission.php',
        'app/Models/ItChange.php',
        'app/Models/ItEmailDelivery.php',
        'app/Models/ItInboundEmail.php',
        'app/Models/ItKbArticle.php',
        'app/Models/ItKbInteraction.php',
        'app/Models/ItMailboxConnection.php',
        'app/Models/ItMajorIncident.php',
        'app/Models/ItMajorIncidentUpdate.php',
        'app/Models/ItProblem.php',
        'app/Models/ItProvisioningRequest.php',
        'app/Models/ItProvisioningTemplate.php',
        'app/Models/ItProvisioningWorkflow.php',
        'app/Models/ItQueue.php',
        'app/Models/ItService.php',
        'app/Models/ItServiceIdentity.php',
        'app/Models/ItSlaPolicy.php',
        'app/Models/ItTeam.php',
        'app/Models/ItTicket.php',
        'app/Models/ItTicketComment.php',
        'app/Models/ItTicketEvent.php',
        'app/Models/ItTicketLink.php',
        'app/Models/ItWorkTask.php',
    ];
}

/** @return list<string> */
function itSecurityHistoricalTenantMigrations(): array
{
    return [
        'database/migrations/2026_02_12_000001_create_integration_framework_tables.php',
        'database/migrations/2026_02_12_000002_create_location_hardware_tables.php',
        'database/migrations/2026_02_12_000003_create_integration_events_and_alerts_tables.php',
        'database/migrations/2026_04_14_000001_create_security_devices_tables.php',
        'database/migrations/2026_05_03_000100_scope_integration_event_deduplication_to_tenant.php',
        'database/migrations/2026_05_11_120000_create_queclink_devices_table.php',
        'database/migrations/2026_05_11_120001_create_queclink_raw_frames_table.php',
        'database/migrations/2026_05_11_120002_create_queclink_pending_commands_table.php',
        'database/migrations/2026_05_19_000002_create_queclink_audit_events_table.php',
        'database/migrations/2026_05_30_000001_create_queclink_presets_table.php',
        'database/migrations/2026_07_02_100001_create_it_provisioning_tables.php',
        'database/migrations/2026_07_07_100002_extend_it_ticketing_schema.php',
        'database/migrations/2026_07_08_100001_create_it_attachments_table.php',
        'database/migrations/2026_07_08_100002_create_it_sla_policies_table.php',
        'database/migrations/2026_07_08_100003_create_it_kb_articles_table.php',
        'database/migrations/2026_07_10_100001_create_it_ticket_approvals_table.php',
        'database/migrations/2026_07_10_100002_create_it_inbound_emails_table.php',
        'database/migrations/2026_07_10_110001_create_it_mailbox_connections_table.php',
        'database/migrations/2026_07_18_100001_create_monitoring_foundation_tables.php',
        'database/migrations/2026_07_18_100002_extend_it_work_and_create_ticket_links.php',
        'database/migrations/2026_07_18_200001_create_it_service_management_core.php',
        'database/migrations/2026_07_18_200002_create_it_service_catalogue.php',
        'database/migrations/2026_07_18_200003_create_it_problem_profiles.php',
        'database/migrations/2026_07_18_200004_create_it_change_profiles.php',
        'database/migrations/2026_07_18_200005_create_it_major_incident_profiles.php',
        'database/migrations/2026_07_18_200006_create_it_service_identities.php',
        'database/migrations/2026_07_18_200007_create_it_provisioning_templates.php',
        'database/migrations/2026_07_18_200008_create_it_service_operations.php',
    ];
}

/** @return list<string> */
function itSecurityApprovedTenantDebt(): array
{
    return [
        'app/Domain/It/Contracts/TracksItEmailDelivery.php|tenant_storage_or_usage|1|0ed7d94ffa49406b',
        'app/Domain/It/Data/ItTransitionInput.php|tenant_parameter|1|cb0c58d8bef17e01',
        'app/Domain/It/InboundEmailIngestor.php|tenant_storage_or_usage|4|db805220ebd534ce',
        'app/Domain/It/ItStaffDirectory.php|organisation_comparison|2|b491921d008bbbb4',
        'app/Domain/It/ItStaffDirectory.php|tenant_parameter|6|53226106b016215e',
        'app/Domain/It/Services/ItApiWorkItemService.php|organisation_comparison|3|2c5f07b23b33d41f',
        'app/Domain/It/Services/ItApiWorkItemService.php|tenant_storage_or_usage|7|d19ae5ec857d3066',
        'app/Domain/It/Services/ItAutomationScheduleCatalog.php|tenant_parameter|4|27cd9731d56a7b07',
        'app/Domain/It/Services/ItAutomationScheduleCatalog.php|tenant_query_scope|1|0d277bef268b0069',
        'app/Domain/It/Services/ItAutomationScheduleCatalog.php|tenant_storage_or_usage|1|26c87cfbb658e9a3',
        'app/Domain/It/Services/ItCatalogSubmissionService.php|tenant_parameter|12|ab6cb7b1306793e5',
        'app/Domain/It/Services/ItCatalogSubmissionService.php|tenant_storage_or_usage|6|a871d89cc355a51a',
        'app/Domain/It/Services/ItChangeService.php|tenant_parameter|14|6b489e8326a1afad',
        'app/Domain/It/Services/ItChangeService.php|tenant_storage_or_usage|3|88b1aeaf4744eef4',
        'app/Domain/It/Services/ItEmailDeliveryService.php|tenant_storage_or_usage|4|989234263e1351eb',
        'app/Domain/It/Services/ItKbLifecycleService.php|organisation_comparison|2|ae6da96d815033e9',
        'app/Domain/It/Services/ItKbLifecycleService.php|tenant_storage_or_usage|2|73ed73ec648b3c95',
        'app/Domain/It/Services/ItMajorIncidentService.php|tenant_parameter|23|2731938d6c55113d',
        'app/Domain/It/Services/ItMajorIncidentService.php|tenant_storage_or_usage|4|7ab3dd05fd5ff2c0',
        'app/Domain/It/Services/ItProblemService.php|tenant_parameter|16|c2e0e4ad8ad8db86',
        'app/Domain/It/Services/ItProblemService.php|tenant_storage_or_usage|3|11e142a9b908ec6e',
        'app/Domain/It/Services/ItProvisioningRequestLifecycleService.php|organisation_comparison|4|38489a39c4affdf7',
        'app/Domain/It/Services/ItProvisioningRequestLifecycleService.php|tenant_storage_or_usage|4|919ab0c9aad0e919',
        'app/Domain/It/Services/ItProvisioningTemplateService.php|organisation_comparison|3|9238211ef89e8052',
        'app/Domain/It/Services/ItProvisioningTemplateService.php|tenant_parameter|12|c855355a222906fe',
        'app/Domain/It/Services/ItProvisioningTemplateService.php|tenant_storage_or_usage|2|6d2f3c979bc795b4',
        'app/Domain/It/Services/ItProvisioningWorkflowService.php|organisation_comparison|1|6c934fea792c7489',
        'app/Domain/It/Services/ItProvisioningWorkflowService.php|tenant_query_scope|3|cc2eaeb6634cc2c5',
        'app/Domain/It/Services/ItProvisioningWorkflowService.php|tenant_storage_or_usage|12|d0a36f6c770d1624',
        'app/Domain/It/Services/ItServiceIdentityCredentialService.php|organisation_comparison|3|7109b05896aefea7',
        'app/Domain/It/Services/ItServiceIdentityCredentialService.php|tenant_parameter|20|b7fcebc1433c5049',
        'app/Domain/It/Services/ItServiceIdentityCredentialService.php|tenant_query_scope|1|8b0ab891122cefad',
        'app/Domain/It/Services/ItServiceIdentityCredentialService.php|tenant_storage_or_usage|1|8a1587f3f7dcc354',
        'app/Domain/It/Services/ItServiceManagementSetupService.php|organisation_comparison|6|d541a3a8092b341d',
        'app/Domain/It/Services/ItServiceManagementSetupService.php|tenant_parameter|41|bb984b399a9efd5d',
        'app/Domain/It/Services/ItServiceManagementSetupService.php|tenant_storage_or_usage|7|f0faf293cef33063',
        'app/Domain/It/Services/ItTicketLinkService.php|tenant_storage_or_usage|4|8d7daabb4dff917b',
        'app/Domain/It/Services/ItTicketRoutingService.php|tenant_query_scope|1|3b359407084eb762',
        'app/Domain/It/Services/ItTicketRoutingService.php|tenant_storage_or_usage|1|dbee9788575d2899',
        'app/Domain/It/Services/ItWorkTaskService.php|organisation_comparison|2|b70f2bbfcbdfe56c',
        'app/Domain/It/Services/ItWorkTaskService.php|tenant_parameter|26|c37a5324a49e42ce',
        'app/Domain/It/Services/ItWorkTaskService.php|tenant_storage_or_usage|8|fb8d0dd0c5958c28',
        'app/Domain/It/Services/ItWorkTransitionService.php|tenant_parameter|1|7511cf2322f226bd',
        'app/Domain/It/Services/ItWorkTransitionService.php|tenant_product_copy|1|79d8016aca57017f',
        'app/Domain/It/Services/ItWorkTransitionService.php|tenant_storage_or_usage|1|ecb8ea65e6ec96e0',
        'app/Domain/Monitoring/Models/Monitor.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Domain/Monitoring/Models/Monitor.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Domain/Monitoring/Models/Monitor.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Domain/Monitoring/Models/MonitorObservation.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Domain/Monitoring/Models/MonitorObservation.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Domain/Monitoring/Models/MonitorObservation.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Domain/Monitoring/Models/MonitoringCollector.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Domain/Monitoring/Models/MonitoringCollector.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Domain/Monitoring/Models/MonitoringCollector.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Domain/Monitoring/Models/MonitoringProfile.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Domain/Monitoring/Models/MonitoringProfile.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Domain/Monitoring/Models/MonitoringProfile.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Domain/Monitoring/Services/MonitoringObservationIngestor.php|tenant_storage_or_usage|2|a8e441f26cab8b94',
        'app/Domain/SecurityDevices/Console/MigrateDevicesCommand.php|tenant_parameter|5|c626c268d4fcc7a4',
        'app/Domain/SecurityDevices/Console/MigrateDevicesCommand.php|tenant_storage_or_usage|7|db6e6459a75d9978',
        'app/Domain/SecurityDevices/Http/Controllers/AlertsEventsController.php|tenant_parameter|2|d6651161f7b12f37',
        'app/Domain/SecurityDevices/Http/Controllers/AlertsEventsController.php|tenant_query_scope|1|ea34f4a18ac0d1d6',
        'app/Domain/SecurityDevices/Http/Controllers/AlertsEventsController.php|tenant_resolver|3|9389f20193ed5f97',
        'app/Domain/SecurityDevices/Http/Controllers/Concerns/ResolvesDeviceTenant.php|organisation_comparison|1|1c5491d28102acf2',
        'app/Domain/SecurityDevices/Http/Controllers/Concerns/ResolvesDeviceTenant.php|tenant_resolver|2|e909c47af9c541f5',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceController.php|organisation_comparison|2|5f5b9674a61f357c',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceController.php|tenant_parameter|4|52951c7f85c83873',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceController.php|tenant_resolver|3|d18f6e11c041a655',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceController.php|tenant_storage_or_usage|12|63dec99b986f0a7f',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php|tenant_resolver|3|d18f6e11c041a655',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php|tenant_storage_or_usage|1|203e656794366788',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php|organisation_comparison|1|1d4ce5f4d73fa5a6',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php|tenant_parameter|16|883824837e61c391',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php|tenant_query_scope|5|157aac7998b6657b',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php|tenant_resolver|6|9639c86a8a14fb50',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php|tenant_secret_contract|19|f716f7843a324ad1',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php|tenant_storage_or_usage|6|dedd87841c03f19f',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php|organisation_comparison|1|1d4ce5f4d73fa5a6',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php|tenant_parameter|15|36c2589448e3627a',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php|tenant_query_scope|5|157aac7998b6657b',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php|tenant_resolver|6|9639c86a8a14fb50',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php|tenant_secret_contract|19|1769062f5768fbbb',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php|tenant_storage_or_usage|6|afd0569e648fdca1',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|organisation_comparison|4|86b50418600b57a7',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|tenant_matcher|3|77525c214750c518',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|tenant_parameter|53|da9a72cfc9bcf486',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|tenant_query_scope|3|3af745e273e88746',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|tenant_resolver|11|ee08953e8a9e8378',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|tenant_secret_contract|2|2f17070309b66091',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|tenant_storage_or_usage|31|139040c9bcf2fa39',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php|organisation_comparison|1|789d4638f0e9010d',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php|tenant_parameter|47|062a5bbfaf164e2a',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php|tenant_query_scope|12|79a3099febd27dc3',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php|tenant_resolver|11|3313672e44c1cad4',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php|tenant_secret_contract|23|d9200a1ef2a39994',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php|tenant_storage_or_usage|17|4e8611bf5c5140a1',
        'app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php|tenant_parameter|2|9e420687b033bd07',
        'app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php|tenant_query_scope|1|74cdf03468293bcf',
        'app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php|tenant_resolver|3|9389f20193ed5f97',
        'app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php|tenant_storage_or_usage|2|6b758389af8a7a6f',
        'app/Domain/SecurityDevices/Http/Controllers/ReportsController.php|tenant_storage_or_usage|1|038f9787e188693e',
        'app/Domain/SecurityDevices/Models/Device.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Domain/SecurityDevices/Models/Device.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Domain/SecurityDevices/Models/Device.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Domain/SecurityDevices/Models/DeviceEvent.php|tenant_parameter|2|785252aff1beeb52',
        'app/Domain/SecurityDevices/Models/DeviceEvent.php|tenant_query_scope|2|694790cd9f7c6c27',
        'app/Domain/SecurityDevices/Models/DeviceGroup.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Domain/SecurityDevices/Models/DeviceGroup.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Domain/SecurityDevices/Models/DeviceGroup.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Domain/SecurityDevices/Models/DeviceMaintenanceRecord.php|tenant_parameter|2|785252aff1beeb52',
        'app/Domain/SecurityDevices/Models/DeviceMaintenanceRecord.php|tenant_query_scope|2|694790cd9f7c6c27',
        'app/Domain/SecurityDevices/Presenters/DeviceProfilePresenter.php|organisation_comparison|4|bb8af83992070d0f',
        'app/Domain/SecurityDevices/Presenters/DeviceProfilePresenter.php|tenant_parameter|7|5b4f848586200969',
        'app/Domain/SecurityDevices/Presenters/DeviceProfilePresenter.php|tenant_query_scope|2|eea1e34b641a7c0c',
        'app/Domain/SecurityDevices/Presenters/DeviceProfilePresenter.php|tenant_storage_or_usage|13|66b6c3b559523f0f',
        'app/Domain/SecurityDevices/Presenters/DiscoveryOperationsPresenter.php|all_tenant_sites_bypass|1|c2b1dc2027a62f3f',
        'app/Domain/SecurityDevices/Presenters/DiscoveryOperationsPresenter.php|tenant_parameter|4|d62a03e172075df7',
        'app/Domain/SecurityDevices/Presenters/DiscoveryOperationsPresenter.php|tenant_query_scope|2|3d819eae2eea58ac',
        'app/Domain/SecurityDevices/Presenters/EstateOperationsPresenter.php|all_tenant_sites_bypass|1|b65ec8a42c166ec1',
        'app/Domain/SecurityDevices/Presenters/EstateOperationsPresenter.php|tenant_parameter|12|0f851d94a4e04178',
        'app/Domain/SecurityDevices/Presenters/EstateOperationsPresenter.php|tenant_query_scope|6|c037ff4c61850de6',
        'app/Domain/SecurityDevices/Presenters/EstateOperationsPresenter.php|tenant_storage_or_usage|3|e349d00ad743b930',
        'app/Domain/SecurityDevices/Presenters/FacilitiesWorkspacePresenter.php|organisation_comparison|3|8455ede9624aba90',
        'app/Domain/SecurityDevices/Presenters/FacilitiesWorkspacePresenter.php|tenant_parameter|3|6a57b107c47d710b',
        'app/Domain/SecurityDevices/Presenters/FacilitiesWorkspacePresenter.php|tenant_query_scope|4|70ecde970ed1fef8',
        'app/Domain/SecurityDevices/Presenters/HealthcareWorkspacePresenter.php|organisation_comparison|2|608e84dc4c42ca57',
        'app/Domain/SecurityDevices/Presenters/HealthcareWorkspacePresenter.php|tenant_parameter|3|7718b8b997a1cc4f',
        'app/Domain/SecurityDevices/Presenters/HealthcareWorkspacePresenter.php|tenant_query_scope|1|4ef81cbd3b116629',
        'app/Domain/SecurityDevices/Presenters/HealthcareWorkspacePresenter.php|tenant_storage_or_usage|2|ffc3d5ecd95033c8',
        'app/Domain/SecurityDevices/Presenters/IntegrationSiteCredentialsPresenter.php|tenant_parameter|3|c1fc1b79ecd909f0',
        'app/Domain/SecurityDevices/Presenters/IntegrationSiteCredentialsPresenter.php|tenant_query_scope|1|77ab6bdfeeab4821',
        'app/Domain/SecurityDevices/Presenters/IntegrationSiteCredentialsPresenter.php|tenant_storage_or_usage|2|f2e6a7f5d6fed980',
        'app/Domain/SecurityDevices/Presenters/IntegrationsWorkspacePresenter.php|all_tenant_sites_bypass|3|11475e011596bb7d',
        'app/Domain/SecurityDevices/Presenters/IntegrationsWorkspacePresenter.php|tenant_parameter|10|b64840f4c049d442',
        'app/Domain/SecurityDevices/Presenters/IntegrationsWorkspacePresenter.php|tenant_query_scope|4|10ee95840ba4a34d',
        'app/Domain/SecurityDevices/Presenters/IntegrationsWorkspacePresenter.php|tenant_secret_contract|8|4a8fe2dee39962c6',
        'app/Domain/SecurityDevices/Presenters/IntegrationsWorkspacePresenter.php|tenant_storage_or_usage|3|9b074f36c7c33cbd',
        'app/Domain/SecurityDevices/Presenters/MonitoringOperationsPresenter.php|tenant_parameter|6|834fd58adcca6746',
        'app/Domain/SecurityDevices/Presenters/MonitoringOperationsPresenter.php|tenant_query_scope|2|d6dafbc8e4d37920',
        'app/Domain/SecurityDevices/Presenters/NetworkItWorkspacePresenter.php|organisation_comparison|2|be7a82fde29bfe44',
        'app/Domain/SecurityDevices/Presenters/NetworkItWorkspacePresenter.php|tenant_parameter|3|572bc27c67f27320',
        'app/Domain/SecurityDevices/Presenters/NetworkItWorkspacePresenter.php|tenant_query_scope|2|85ce20d61a188e93',
        'app/Domain/SecurityDevices/Presenters/NetworkItWorkspacePresenter.php|tenant_storage_or_usage|2|b243e2df68720c01',
        'app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php|all_tenant_sites_bypass|9|786e5cabf30b4653',
        'app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php|tenant_parameter|18|42e7d023edf68d13',
        'app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php|tenant_query_scope|2|562fc974f687cf56',
        'app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php|tenant_secret_contract|4|b58b7c44489f9961',
        'app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php|tenant_storage_or_usage|7|20340afdb78bcc9d',
        'app/Domain/SecurityDevices/Presenters/TrackingWorkspacePresenter.php|organisation_comparison|12|8b420faa77c97b31',
        'app/Domain/SecurityDevices/Presenters/TrackingWorkspacePresenter.php|tenant_parameter|8|d915547150abc91d',
        'app/Domain/SecurityDevices/Presenters/TrackingWorkspacePresenter.php|tenant_storage_or_usage|11|6d4c3fad48c6bb8f',
        'app/Domain/SecurityDevices/Services/DeviceGroupAutoRuleService.php|tenant_parameter|3|d02c0784d44deb9b',
        'app/Domain/SecurityDevices/Services/DeviceGroupAutoRuleService.php|tenant_storage_or_usage|3|c7c10f89388c621d',
        'app/Domain/SecurityDevices/Services/DeviceRegistryService.php|tenant_parameter|18|0558d9ca7b17ca25',
        'app/Domain/SecurityDevices/Services/DeviceRegistryService.php|tenant_query_scope|1|d6aeeb2e7372c6ac',
        'app/Domain/SecurityDevices/Services/QueclinkIntegrationAccessService.php|tenant_matcher|1|5f1f06cf108886c1',
        'app/Domain/SecurityDevices/Services/QueclinkIntegrationAccessService.php|tenant_parameter|8|6eb89211f57ff12f',
        'app/Domain/SecurityDevices/Services/QueclinkIntegrationAccessService.php|tenant_storage_or_usage|7|b191e45408f47b13',
        'app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php|all_tenant_sites_bypass|5|27406aa12958808f',
        'app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php|organisation_comparison|16|d4cac3204d36f3d3',
        'app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php|tenant_matcher|10|87bfe460eddfaf3b',
        'app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php|tenant_parameter|84|b362e157a03b67a0',
        'app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php|tenant_query_scope|5|7284d8792938e443',
        'app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php|tenant_storage_or_usage|45|8ecc94df5094361c',
        'app/Http/Controllers/Api/ItApiWorkItemController.php|tenant_parameter|1|46dc43815b5de3f6',
        'app/Http/Controllers/Api/ItApiWorkItemController.php|tenant_storage_or_usage|1|03a0e1e5697895f8',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|organisation_comparison|1|2e74988b7353db09',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|tenant_parameter|7|935013fc0f5e22f3',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|tenant_resolver|2|fb97941227f38f21',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|tenant_storage_or_usage|13|61ea4714296be36c',
        'app/Http/Controllers/It/Concerns/BuildsItOptions.php|tenant_parameter|4|cd8cda2437317fa4',
        'app/Http/Controllers/It/Concerns/BuildsItOptions.php|tenant_product_copy|1|13c3feba1b094927',
        'app/Http/Controllers/It/Concerns/BuildsItOptions.php|tenant_query_scope|1|03c633efffcdc37e',
        'app/Http/Controllers/It/Concerns/BuildsItOptions.php|tenant_storage_or_usage|1|727c743af4e6143b',
        'app/Http/Controllers/It/Concerns/StoresItAttachments.php|tenant_storage_or_usage|2|5751d8b7286af874',
        'app/Http/Controllers/It/ItCatalogController.php|tenant_parameter|6|73dd17c33e3bfe04',
        'app/Http/Controllers/It/ItCatalogController.php|tenant_query_scope|2|407ac43209ac4590',
        'app/Http/Controllers/It/ItCatalogController.php|tenant_resolver|4|4cceafd34e76109e',
        'app/Http/Controllers/It/ItChangeController.php|tenant_parameter|12|a53d245c2edf8cfd',
        'app/Http/Controllers/It/ItChangeController.php|tenant_query_scope|2|41e7dd0429a18151',
        'app/Http/Controllers/It/ItChangeController.php|tenant_resolver|6|b958bb5f80914757',
        'app/Http/Controllers/It/ItChangeController.php|tenant_storage_or_usage|2|c23ad8573f047278',
        'app/Http/Controllers/It/ItKbController.php|tenant_parameter|11|46d2e95c10f06f60',
        'app/Http/Controllers/It/ItKbController.php|tenant_product_copy|2|47913c1474f8a7ba',
        'app/Http/Controllers/It/ItKbController.php|tenant_resolver|7|136050b2f2e9b570',
        'app/Http/Controllers/It/ItKbController.php|tenant_storage_or_usage|9|2e59b2a79685479c',
        'app/Http/Controllers/It/ItMajorIncidentController.php|tenant_parameter|19|be6064d5f1c33b49',
        'app/Http/Controllers/It/ItMajorIncidentController.php|tenant_query_scope|1|0aaac1629fb0c6bb',
        'app/Http/Controllers/It/ItMajorIncidentController.php|tenant_resolver|8|1cacca549b3fbe4d',
        'app/Http/Controllers/It/ItMajorIncidentController.php|tenant_storage_or_usage|3|4b63e09c4dab8a5d',
        'app/Http/Controllers/It/ItProblemController.php|tenant_parameter|6|4d944c87287e2558',
        'app/Http/Controllers/It/ItProblemController.php|tenant_resolver|5|3b0c795a1107062e',
        'app/Http/Controllers/It/ItProblemController.php|tenant_storage_or_usage|1|eb701854a8471b29',
        'app/Http/Controllers/It/ItProvisioningController.php|tenant_parameter|80|1812e1fdad6235fe',
        'app/Http/Controllers/It/ItProvisioningController.php|tenant_product_copy|4|46919f86175098e9',
        'app/Http/Controllers/It/ItProvisioningController.php|tenant_query_scope|9|46ce1145f00b87d5',
        'app/Http/Controllers/It/ItProvisioningController.php|tenant_resolver|15|a5611d862df69c48',
        'app/Http/Controllers/It/ItProvisioningController.php|tenant_storage_or_usage|26|c1a6e079383294ba',
        'app/Http/Controllers/It/ItReportsController.php|tenant_parameter|20|735c007dc5b33b9d',
        'app/Http/Controllers/It/ItReportsController.php|tenant_product_copy|2|6c60edb987e83d60',
        'app/Http/Controllers/It/ItReportsController.php|tenant_query_scope|5|1b4d28e450090a16',
        'app/Http/Controllers/It/ItReportsController.php|tenant_resolver|4|4cceafd34e76109e',
        'app/Http/Controllers/It/ItServiceManagementSetupController.php|tenant_parameter|41|1f3fa9c4a66787cf',
        'app/Http/Controllers/It/ItServiceManagementSetupController.php|tenant_query_scope|10|98d82c3f2197975c',
        'app/Http/Controllers/It/ItServiceManagementSetupController.php|tenant_resolver|8|16a8d5368852d78e',
        'app/Http/Controllers/It/ItServiceManagementSetupController.php|tenant_storage_or_usage|5|476f0b1cb83de7ae',
        'app/Http/Controllers/It/ItTicketController.php|tenant_parameter|33|4f3960f757c62ab4',
        'app/Http/Controllers/It/ItTicketController.php|tenant_product_copy|1|25e64053c3fb7572',
        'app/Http/Controllers/It/ItTicketController.php|tenant_resolver|12|238240a4d599f286',
        'app/Http/Controllers/It/ItTicketController.php|tenant_storage_or_usage|17|921ee49505d70e79',
        'app/Http/Controllers/It/ItWorkTaskController.php|tenant_parameter|10|447ae57173155ac1',
        'app/Http/Controllers/It/ItWorkTaskController.php|tenant_resolver|3|d678e61e24280d3f',
        'app/Http/Controllers/Settings/ItMailboxOAuthController.php|tenant_resolver|4|49b838b78d2d8044',
        'app/Http/Controllers/Settings/ItMailboxOAuthController.php|tenant_storage_or_usage|2|dde7ae5cf0e6f28b',
        'app/Http/Controllers/Settings/ItMailboxSettingsController.php|tenant_parameter|2|b3165912cfdba1f0',
        'app/Http/Controllers/Settings/ItMailboxSettingsController.php|tenant_resolver|4|32f5093db5ec47e3',
        'app/Http/Controllers/Settings/ItMailboxSettingsController.php|tenant_storage_or_usage|2|98b0d55b964dbccb',
        'app/Http/Middleware/RecordItApiRequest.php|organisation_comparison|2|a17c4566b8ddf75e',
        'app/Http/Middleware/RecordItApiRequest.php|tenant_storage_or_usage|4|54a3f00b0661a185',
        'app/Listeners/It/CreateOrUpdateMonitoringTicket.php|tenant_storage_or_usage|1|02ca858e5a8381c5',
        'app/Models/Integration/Integration.php|tenant_parameter|2|bbb71bb492183d4d',
        'app/Models/Integration/Integration.php|tenant_query_scope|1|fdfec64ad98adf55',
        'app/Models/Integration/Integration.php|tenant_secret_contract|2|8e6f73009f03d5e0',
        'app/Models/Integration/Integration.php|tenant_storage_or_usage|5|cd742d823decfa1e',
        'app/Models/Integration/IntegrationEvent.php|tenant_parameter|3|0a26eeb7f7f2d8aa',
        'app/Models/Integration/IntegrationEvent.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/Integration/IntegrationEvent.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/Integration/IntegrationSiteConfig.php|tenant_parameter|2|bbb71bb492183d4d',
        'app/Models/Integration/IntegrationSiteConfig.php|tenant_query_scope|1|fdfec64ad98adf55',
        'app/Models/Integration/IntegrationSiteConfig.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/Integration/IntegrationSiteSecret.php|tenant_parameter|2|bbb71bb492183d4d',
        'app/Models/Integration/IntegrationSiteSecret.php|tenant_query_scope|1|fdfec64ad98adf55',
        'app/Models/Integration/IntegrationSiteSecret.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/Integration/IntegrationSyncLog.php|tenant_parameter|2|bbb71bb492183d4d',
        'app/Models/Integration/IntegrationSyncLog.php|tenant_query_scope|1|fdfec64ad98adf55',
        'app/Models/Integration/IntegrationSyncLog.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/Integration/IntegrationTenantSecret.php|tenant_parameter|2|bbb71bb492183d4d',
        'app/Models/Integration/IntegrationTenantSecret.php|tenant_query_scope|1|fdfec64ad98adf55',
        'app/Models/Integration/IntegrationTenantSecret.php|tenant_secret_contract|1|e5fdfea6cb8f8e9a',
        'app/Models/Integration/IntegrationTenantSecret.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItApiRequest.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItApiRequest.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItApiRequest.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItAutomationRun.php|tenant_parameter|2|75b5a0fd0eaff4f6',
        'app/Models/ItAutomationRun.php|tenant_query_scope|1|6ec0874ee4b5f4ea',
        'app/Models/ItAutomationRun.php|tenant_storage_or_usage|3|c4ebf44b3707b3a8',
        'app/Models/ItCatalogItem.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItCatalogItem.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItCatalogItem.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItChange.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItChange.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItChange.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItEmailDelivery.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItEmailDelivery.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItEmailDelivery.php|tenant_storage_or_usage|2|1fb530e0ccd61224',
        'app/Models/ItKbArticle.php|tenant_parameter|4|85466570b84e5d6b',
        'app/Models/ItKbArticle.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/ItKbArticle.php|tenant_storage_or_usage|2|c859032dddeee48b',
        'app/Models/ItKbInteraction.php|tenant_storage_or_usage|1|c3757aeba0ac2d70',
        'app/Models/ItMajorIncident.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItMajorIncident.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItMajorIncident.php|tenant_storage_or_usage|2|e667327c3f84211a',
        'app/Models/ItMajorIncidentUpdate.php|tenant_storage_or_usage|1|7e6600083f16a50b',
        'app/Models/ItProblem.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItProblem.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItProblem.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItProvisioningRequest.php|tenant_parameter|2|5b43ba058652571d',
        'app/Models/ItProvisioningRequest.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/ItProvisioningRequest.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItProvisioningTemplate.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItProvisioningTemplate.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItProvisioningTemplate.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItProvisioningWorkflow.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItProvisioningWorkflow.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItProvisioningWorkflow.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItQueue.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Models/ItQueue.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Models/ItQueue.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItService.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Models/ItService.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Models/ItService.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItServiceIdentity.php|tenant_parameter|2|478428ed252f7e09',
        'app/Models/ItServiceIdentity.php|tenant_query_scope|1|3bdf692f497edaf7',
        'app/Models/ItServiceIdentity.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItSlaPolicy.php|tenant_parameter|4|2da26721c7e8b812',
        'app/Models/ItSlaPolicy.php|tenant_storage_or_usage|2|955686719437e330',
        'app/Models/ItTeam.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Models/ItTeam.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Models/ItTeam.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItTicket.php|tenant_parameter|4|2250b9cebf5c9974',
        'app/Models/ItTicket.php|tenant_query_scope|2|bc51a5dd32587368',
        'app/Models/ItTicket.php|tenant_storage_or_usage|4|1137c8c0203e0fc9',
        'app/Models/ItTicketApproval.php|tenant_storage_or_usage|1|93bbf596d6a56e49',
        'app/Models/ItTicketEvent.php|tenant_storage_or_usage|2|b2f162cbcf16ed34',
        'app/Models/ItTicketLink.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Models/ItTicketLink.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Models/ItTicketLink.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItWorkTask.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Models/ItWorkTask.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Models/ItWorkTask.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/LocationHardware.php|tenant_parameter|3|0a26eeb7f7f2d8aa',
        'app/Models/LocationHardware.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/LocationHardware.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/Queclink/QueclinkPreset.php|tenant_parameter|4|c52396844305e63c',
        'app/Models/Queclink/QueclinkPreset.php|tenant_storage_or_usage|2|13f3af3c8ffb2faa',
        'app/Models/SiteRoom.php|tenant_parameter|3|0a26eeb7f7f2d8aa',
        'app/Models/SiteRoom.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/SiteRoom.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'docs/security-devices-restructure-plan.md|tenant_product_copy|3|26c1108221219204',
        'docs/security-devices-restructure-plan.md|tenant_secret_contract|1|fb2385b298e131cc',
        'docs/security-devices-restructure-plan.md|tenant_storage_or_usage|4|8a48232e6913d7bc',
        'docs/superpowers/plans/2026-07-18-it-support-service-management-expansion.md|tenant_product_copy|3|fe82340ddf72e6b5',
        'resources/js/pages/security-devices/integrations/milesight.tsx|tenant_secret_contract|10|71f7930296065c8f',
        'resources/js/pages/security-devices/integrations/queclink.tsx|tenant_secret_contract|10|7fa0a2e3ac97be39',
        'resources/js/pages/security-devices/integrations/unifi.tsx|tenant_secret_contract|13|2b5e1e267c912d73',
        'resources/js/pages/security-devices/reports.tsx|tenant_product_copy|1|15f2b031177033e2',
        'tests/Feature/It/ItChangeManagementTest.php|organisation_comparison|1|49febc01c0939c78',
        'tests/Feature/It/ItChangeManagementTest.php|tenant_parameter|2|a918587e436c5548',
        'tests/Feature/It/ItChangeManagementTest.php|tenant_storage_or_usage|6|226b1b4107226b8d',
        'tests/Feature/It/ItEmailInboundTest.php|organisation_comparison|3|4a4e9d3888d0bc22',
        'tests/Feature/It/ItEmailInboundTest.php|tenant_storage_or_usage|5|30beb2b2ca576323',
        'tests/Feature/It/ItIngressContextAccessTest.php|tenant_storage_or_usage|6|eb00cf14bbbc25fb',
        'tests/Feature/It/ItKbTest.php|tenant_product_copy|1|64015e1ffb91b9bb',
        'tests/Feature/It/ItKbTest.php|tenant_storage_or_usage|3|3520fea4541d8f68',
        'tests/Feature/It/ItMailboxConnectionTest.php|tenant_storage_or_usage|1|fe0ecf220b5bc442',
        'tests/Feature/It/ItMailboxPollTest.php|organisation_comparison|2|12bca6c1218d2955',
        'tests/Feature/It/ItMailboxPollTest.php|tenant_storage_or_usage|2|37e583be1566a43f',
        'tests/Feature/It/ItMailboxSettingsTest.php|tenant_storage_or_usage|1|47df747d13fc36a0',
        'tests/Feature/It/ItMajorIncidentManagementTest.php|organisation_comparison|1|49febc01c0939c78',
        'tests/Feature/It/ItMajorIncidentManagementTest.php|tenant_parameter|2|b5aa46e63f3a1566',
        'tests/Feature/It/ItMajorIncidentManagementTest.php|tenant_product_copy|1|e77feebde1756171',
        'tests/Feature/It/ItMajorIncidentManagementTest.php|tenant_storage_or_usage|7|f0ebf8da824b90f6',
        'tests/Feature/It/ItMonitoringTicketIntegrationTest.php|tenant_storage_or_usage|2|7c6668132ccb2e27',
        'tests/Feature/It/ItProblemManagementTest.php|organisation_comparison|1|49febc01c0939c78',
        'tests/Feature/It/ItProblemManagementTest.php|tenant_parameter|2|f1d135255c09e14e',
        'tests/Feature/It/ItProblemManagementTest.php|tenant_storage_or_usage|8|1a223d0196912422',
        'tests/Feature/It/ItProvisioningBulkTest.php|tenant_product_copy|3|3cfe703ee4437f2a',
        'tests/Feature/It/ItProvisioningBulkTest.php|tenant_storage_or_usage|10|2b07563c11ef134f',
        'tests/Feature/It/ItProvisioningTest.php|tenant_product_copy|1|c39efb2b210d7dd3',
        'tests/Feature/It/ItProvisioningTest.php|tenant_storage_or_usage|13|324cf0cf41ab1c13',
        'tests/Feature/It/ItProvisioningWorkflowTest.php|organisation_comparison|3|1f0f2eea8978494b',
        'tests/Feature/It/ItProvisioningWorkflowTest.php|tenant_product_copy|1|6be34cb03ee20fa1',
        'tests/Feature/It/ItProvisioningWorkflowTest.php|tenant_storage_or_usage|14|4a3da12a0f030cd7',
        'tests/Feature/It/ItReportsTest.php|tenant_storage_or_usage|6|325570236409cde4',
        'tests/Feature/It/ItSecureApiAccessBoundaryTest.php|organisation_comparison|1|90d7d7480075cec6',
        'tests/Feature/It/ItSecureApiAccessBoundaryTest.php|tenant_parameter|1|4be69c2e1b1507e2',
        'tests/Feature/It/ItSecureApiAccessBoundaryTest.php|tenant_storage_or_usage|1|90ce3f459227eaaa',
        'tests/Feature/It/ItSecureApiTest.php|organisation_comparison|2|1b3e04cc25057da0',
        'tests/Feature/It/ItSecureApiTest.php|tenant_parameter|2|8e18974a493bef52',
        'tests/Feature/It/ItSecureApiTest.php|tenant_storage_or_usage|1|b9bef80b26ef21c4',
        'tests/Feature/It/ItServiceCatalogTest.php|organisation_comparison|1|49febc01c0939c78',
        'tests/Feature/It/ItServiceCatalogTest.php|tenant_parameter|2|7aa8ad6bf2bba3de',
        'tests/Feature/It/ItServiceCatalogTest.php|tenant_product_copy|3|44b87eea87981b01',
        'tests/Feature/It/ItServiceCatalogTest.php|tenant_storage_or_usage|6|43a54d5fc74a5642',
        'tests/Feature/It/ItServiceManagementSchemaTest.php|tenant_product_copy|1|c519126647471329',
        'tests/Feature/It/ItServiceManagementSchemaTest.php|tenant_query_scope|2|8860eca75e20cdf6',
        'tests/Feature/It/ItServiceManagementSchemaTest.php|tenant_storage_or_usage|11|dfc57454f94daee2',
        'tests/Feature/It/ItServiceManagementSetupTest.php|organisation_comparison|1|49febc01c0939c78',
        'tests/Feature/It/ItServiceManagementSetupTest.php|tenant_parameter|2|d4de25d613918dbc',
        'tests/Feature/It/ItServiceManagementSetupTest.php|tenant_storage_or_usage|8|fa85fe893afc8956',
        'tests/Feature/It/ItServiceOperationsTest.php|organisation_comparison|1|49febc01c0939c78',
        'tests/Feature/It/ItServiceOperationsTest.php|tenant_parameter|2|87709f4819b3c9e3',
        'tests/Feature/It/ItServiceOperationsTest.php|tenant_storage_or_usage|30|08aa427641362540',
        'tests/Feature/It/ItSlaCommandTest.php|tenant_storage_or_usage|1|4b7723cdb7abccbf',
        'tests/Feature/It/ItSlaTest.php|tenant_storage_or_usage|7|ec49b3233095f5e0',
        'tests/Feature/It/ItTicketApprovalTest.php|tenant_storage_or_usage|22|cb813d77c791bb07',
        'tests/Feature/It/ItTicketAuthzTest.php|tenant_storage_or_usage|4|0a670ba248fa9bee',
        'tests/Feature/It/ItTicketBulkTest.php|tenant_product_copy|2|ae720e5d398dbd42',
        'tests/Feature/It/ItTicketBulkTest.php|tenant_storage_or_usage|1|50d6a697be8d8d00',
        'tests/Feature/It/ItTicketMergeTest.php|tenant_storage_or_usage|18|9b9950392086df56',
        'tests/Feature/It/ItTicketWorkspaceTest.php|tenant_storage_or_usage|10|ae07ff2e242ba251',
        'tests/Feature/It/ItTicketingSchemaTest.php|tenant_storage_or_usage|4|60566d1630100166',
        'tests/Feature/It/ItWorkAccessServiceTest.php|tenant_storage_or_usage|4|c3d820b7596af6f5',
        'tests/Feature/It/ItWorkTaskTest.php|organisation_comparison|2|1ce2b694d1708945',
        'tests/Feature/It/ItWorkTaskTest.php|tenant_parameter|2|430aaa273791cafa',
        'tests/Feature/It/ItWorkTaskTest.php|tenant_product_copy|2|7f22304c3bc7cafe',
        'tests/Feature/It/ItWorkTaskTest.php|tenant_storage_or_usage|11|60fb8098bdf634c0',
        'tests/Feature/It/ItWorkTransitionTest.php|organisation_comparison|2|f66fa140beb45615',
        'tests/Feature/It/ItWorkTransitionTest.php|tenant_parameter|10|9e44e3adab60cb7b',
        'tests/Feature/It/ItWorkTransitionTest.php|tenant_product_copy|1|1e1a0881ece24807',
        'tests/Feature/It/ItWorkTransitionTest.php|tenant_storage_or_usage|3|73091fa511490682',
        'tests/Feature/Monitoring/MonitoringDeliveryTest.php|organisation_comparison|3|25790f063ee2f159',
        'tests/Feature/Monitoring/MonitoringSchemaTest.php|tenant_query_scope|2|7b1d9a17d385501d',
        'tests/Feature/Monitoring/MonitoringSchemaTest.php|tenant_storage_or_usage|9|7b836260683a19d1',
        'tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php|tenant_storage_or_usage|4|5982c3b52df7a0d4',
        'tests/Feature/SecurityDevices/AlertsEventsControllerTest.php|organisation_comparison|1|0a88791b4d93bc24',
        'tests/Feature/SecurityDevices/AlertsEventsControllerTest.php|tenant_storage_or_usage|2|03c8628b6520729d',
        'tests/Feature/SecurityDevices/AssetTrackerRetirementTest.php|organisation_comparison|2|179df2c6ef3222da',
        'tests/Feature/SecurityDevices/AssetTrackerRetirementTest.php|tenant_storage_or_usage|2|2efd389753b0c7e0',
        'tests/Feature/SecurityDevices/CanonicalIntegrationEventHistoryTest.php|tenant_storage_or_usage|4|338481af11178800',
        'tests/Feature/SecurityDevices/CategoryPageControllerTest.php|organisation_comparison|1|0a88791b4d93bc24',
        'tests/Feature/SecurityDevices/CategoryPageControllerTest.php|tenant_storage_or_usage|2|62a7fa61f66a57fc',
        'tests/Feature/SecurityDevices/ClientDeviceRefactorTest.php|tenant_storage_or_usage|4|940af182cf5e05eb',
        'tests/Feature/SecurityDevices/DashboardControllerTest.php|organisation_comparison|1|0a88791b4d93bc24',
        'tests/Feature/SecurityDevices/DashboardControllerTest.php|tenant_storage_or_usage|6|d12d070e9a7cda50',
        'tests/Feature/SecurityDevices/DeviceAssignmentControllerTest.php|organisation_comparison|5|edea780f00743573',
        'tests/Feature/SecurityDevices/DeviceAssignmentControllerTest.php|tenant_storage_or_usage|28|a8ae2682bba82578',
        'tests/Feature/SecurityDevices/DeviceControllerTest.php|organisation_comparison|4|d82898d33cfc7f26',
        'tests/Feature/SecurityDevices/DeviceControllerTest.php|tenant_storage_or_usage|12|895e1d2566e1ead1',
        'tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php|tenant_storage_or_usage|6|8d87366e24c36e57',
        'tests/Feature/SecurityDevices/DeviceGroupAutoRulesTest.php|tenant_storage_or_usage|4|cc248c134096f1f3',
        'tests/Feature/SecurityDevices/DeviceGroupControllerTest.php|organisation_comparison|1|a582cfcc70cd514b',
        'tests/Feature/SecurityDevices/DeviceGroupControllerTest.php|tenant_storage_or_usage|19|c17a97e93dddf131',
        'tests/Feature/SecurityDevices/DeviceMutationAccessBoundaryTest.php|organisation_comparison|13|771f3b65883c17b0',
        'tests/Feature/SecurityDevices/DeviceMutationAccessBoundaryTest.php|tenant_storage_or_usage|13|5c7517982f437492',
        'tests/Feature/SecurityDevices/DeviceProfileWorkspaceTest.php|organisation_comparison|5|d6d59a0d4d2d055c',
        'tests/Feature/SecurityDevices/DeviceProfileWorkspaceTest.php|tenant_storage_or_usage|37|c15a04190752d76b',
        'tests/Feature/SecurityDevices/EstateSiteOperationsTest.php|organisation_comparison|2|e6cc07658fe65c89',
        'tests/Feature/SecurityDevices/EstateSiteOperationsTest.php|tenant_storage_or_usage|32|3505e117c6f3ec3a',
        'tests/Feature/SecurityDevices/FacilitiesWorkspaceTest.php|organisation_comparison|1|e6b46a9c9c6e97f2',
        'tests/Feature/SecurityDevices/FacilitiesWorkspaceTest.php|tenant_storage_or_usage|8|d57c43973fff4397',
        'tests/Feature/SecurityDevices/FinanceDeviceHealthTest.php|organisation_comparison|6|33e19d5a64cf8f93',
        'tests/Feature/SecurityDevices/HealthcareWorkspaceTest.php|organisation_comparison|10|353a795ad1d0386c',
        'tests/Feature/SecurityDevices/HealthcareWorkspaceTest.php|tenant_storage_or_usage|8|7d7d74d3aca8f603',
        'tests/Feature/SecurityDevices/IntegrationsHubTest.php|organisation_comparison|8|a4b179725fb7a674',
        'tests/Feature/SecurityDevices/IntegrationsHubTest.php|tenant_secret_contract|16|d823ded2c4597853',
        'tests/Feature/SecurityDevices/IntegrationsHubTest.php|tenant_storage_or_usage|47|c6190f1abd71be8c',
        'tests/Feature/SecurityDevices/LocationHardwareRetirementTest.php|organisation_comparison|3|932760977f978de9',
        'tests/Feature/SecurityDevices/LocationHardwareRetirementTest.php|tenant_storage_or_usage|3|3820974871b01211',
        'tests/Feature/SecurityDevices/MaintenanceHealthControllerTest.php|organisation_comparison|2|b3653054df14f926',
        'tests/Feature/SecurityDevices/MaintenanceHealthControllerTest.php|tenant_storage_or_usage|3|46d74129a8aa27a1',
        'tests/Feature/SecurityDevices/NetworkItWorkspaceTest.php|organisation_comparison|1|e6b46a9c9c6e97f2',
        'tests/Feature/SecurityDevices/NetworkItWorkspaceTest.php|tenant_storage_or_usage|11|5be666744fea24fc',
        'tests/Feature/SecurityDevices/NonUnifiHealthPullMigrationTest.php|tenant_secret_contract|15|694f0b7306c3a43b',
        'tests/Feature/SecurityDevices/NonUnifiHealthPullMigrationTest.php|tenant_storage_or_usage|10|50a4b088faef9e1d',
        'tests/Feature/SecurityDevices/OperationsWorkspacesTest.php|organisation_comparison|2|e6cc07658fe65c89',
        'tests/Feature/SecurityDevices/OperationsWorkspacesTest.php|tenant_storage_or_usage|28|6976abcff36ccf45',
        'tests/Feature/SecurityDevices/ReportsExportTest.php|organisation_comparison|1|0a88791b4d93bc24',
        'tests/Feature/SecurityDevices/ReportsExportTest.php|tenant_storage_or_usage|3|de5657f746cfe208',
        'tests/Feature/SecurityDevices/ResidentTrackingRefactorTest.php|organisation_comparison|3|8b44778786b70bcf',
        'tests/Feature/SecurityDevices/ResidentTrackingRefactorTest.php|tenant_storage_or_usage|11|706683e2c309c085',
        'tests/Feature/SecurityDevices/SecurityDevicesNavigationRoutesTest.php|organisation_comparison|2|3d3324ba87f20af8',
        'tests/Feature/SecurityDevices/SecurityDevicesNavigationRoutesTest.php|tenant_product_copy|2|71774f77930b24d7',
        'tests/Feature/SecurityDevices/SecurityDevicesNavigationRoutesTest.php|tenant_storage_or_usage|7|f71b3e4a0309e330',
        'tests/Feature/SecurityDevices/SecurityWorkspaceTest.php|organisation_comparison|2|e6cc07658fe65c89',
        'tests/Feature/SecurityDevices/SecurityWorkspaceTest.php|tenant_storage_or_usage|16|89cbbe883329dbb5',
        'tests/Feature/SecurityDevices/SettingsAuditTest.php|organisation_comparison|51|819162af55da2ce8',
        'tests/Feature/SecurityDevices/SettingsAuditTest.php|tenant_secret_contract|6|112e381a727815ef',
        'tests/Feature/SecurityDevices/SettingsAuditTest.php|tenant_storage_or_usage|105|2e3ddfd6e8adf59c',
        'tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php|tenant_parameter|2|bc5f2773e5ad45bf',
        'tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php|tenant_product_copy|1|7a2ee69e882e8e65',
        'tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php|tenant_storage_or_usage|32|81c973098d84f28c',
        'tests/Feature/SecurityDevices/TrackingWorkspaceTest.php|organisation_comparison|7|895a76ea18fd29ec',
        'tests/Feature/SecurityDevices/TrackingWorkspaceTest.php|tenant_storage_or_usage|4|ff84ee8ec9cb5217',
        'tests/Feature/SecurityDevices/UnifiDiscoveryFailureTest.php|organisation_comparison|1|9c89a450fe1403ee',
        'tests/Feature/SecurityDevices/UnifiDiscoveryFailureTest.php|tenant_secret_contract|15|05886e0e26bd16aa',
        'tests/Feature/SecurityDevices/UnifiDiscoveryFailureTest.php|tenant_storage_or_usage|2|cce3a4fefe5d7cfe',
        'tests/Feature/SecurityDevices/UnifiOperationalBridgeMigrationTest.php|tenant_secret_contract|11|30574617e4d5b6d5',
        'tests/Feature/SecurityDevices/UnifiOperationalBridgeMigrationTest.php|tenant_storage_or_usage|17|01f19787847ac485',
        'tests/Feature/SecurityDevices/UnifiSettingsRefactorTest.php|tenant_secret_contract|9|baf9cecbb5ef89e9',
        'tests/Feature/SecurityDevices/UnifiSettingsRefactorTest.php|tenant_storage_or_usage|53|5eac8caf0a2b46f7',
        'tests/Feature/SecurityDevices/WorkspaceCompatibilityTest.php|organisation_comparison|2|984bfa76ef518805',
        'tests/Feature/SecurityDevices/WorkspaceCompatibilityTest.php|tenant_storage_or_usage|3|f13675f734cafcc0',
        'tests/Unit/SecurityDevices/DeviceModelTest.php|tenant_query_scope|2|a72cf3d104dfc86e',
        'tests/Unit/SecurityDevices/DeviceModelTest.php|tenant_storage_or_usage|5|6b784b2814591eb2',
        'tests/Unit/SecurityDevices/DeviceRegistryServiceTest.php|tenant_parameter|30|1f3c27ec8897c389',
        'tests/Unit/SecurityDevices/DeviceRegistryServiceTest.php|tenant_storage_or_usage|20|f3b953ea2bd62ae8',
        'tests/Unit/SecurityDevices/MigrateDevicesCommandTest.php|tenant_storage_or_usage|2|e80dccfe197dc693',
    ];
}
