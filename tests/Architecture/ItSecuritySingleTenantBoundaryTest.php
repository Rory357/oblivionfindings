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
        class Device {
            protected $fillable = [
                'tenant_id',
            ];
        }
        PHP;

    expect(itSecurityScanTenantSource('app/Domain/SecurityDevices/Models/Device.php', $storageDeclaration))
        ->not->toHaveKey('tenant_storage_or_usage')
        ->and(itSecurityScanTenantSource('app/Domain/SecurityDevices/Models/NewDevice.php', $storageDeclaration))
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
        'app/Models/Integration/IntegrationProviderConnection.php',
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
        'app/Domain/It/InboundEmailIngestor.php|tenant_storage_or_usage|3|b1e5eb948e15c570',
        'app/Domain/It/Services/ItApiWorkItemService.php|tenant_storage_or_usage|2|4f8302fb81d03cc5',
        'app/Domain/It/Services/ItCatalogSubmissionService.php|tenant_storage_or_usage|3|bc0fe15c3083a8cb',
        'app/Domain/It/Services/ItChangeService.php|tenant_storage_or_usage|2|0bc91ca03e1654c3',
        'app/Domain/It/Services/ItEmailDeliveryService.php|tenant_storage_or_usage|2|2ed20fd142cd5f7b',
        'app/Domain/It/Services/ItMajorIncidentService.php|tenant_storage_or_usage|3|128badca08a7561c',
        'app/Domain/It/Services/ItProblemService.php|tenant_storage_or_usage|2|05c33990239ff982',
        'app/Domain/It/Services/ItProvisioningTemplateService.php|tenant_storage_or_usage|1|be106aa356ce33a8',
        'app/Domain/It/Services/ItProvisioningWorkflowService.php|tenant_storage_or_usage|4|48e143ff56ad1609',
        'app/Domain/It/Services/ItServiceIdentityCredentialService.php|tenant_storage_or_usage|1|892342661856ec7c',
        'app/Domain/It/Services/ItServiceManagementSetupService.php|tenant_storage_or_usage|3|bfb9582a8486b739',
        'app/Domain/It/Services/ItTicketLinkService.php|tenant_storage_or_usage|2|625c39a9d1e487b2',
        'app/Domain/It/Services/ItWorkTaskService.php|tenant_storage_or_usage|1|f7915255c9b5d99f',
        'app/Domain/SecurityDevices/Console/MigrateDevicesCommand.php|tenant_parameter|5|c626c268d4fcc7a4',
        'app/Domain/SecurityDevices/Console/MigrateDevicesCommand.php|tenant_storage_or_usage|7|db6e6459a75d9978',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceController.php|tenant_storage_or_usage|1|b64c761624fab3fd',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php|tenant_storage_or_usage|1|b64c761624fab3fd',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php|tenant_storage_or_usage|4|b5788ae320e5c57d',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php|tenant_storage_or_usage|4|631028f44df45c64',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php|tenant_storage_or_usage|8|f0ac101298a568d0',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php|tenant_storage_or_usage|8|3e187f63ec414652',
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
        'app/Domain/SecurityDevices/Presenters/IntegrationSiteCredentialsPresenter.php|tenant_storage_or_usage|1|4ba1b8aacb304726',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|organisation_comparison|1|2e74988b7353db09',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|tenant_parameter|7|935013fc0f5e22f3',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|tenant_resolver|2|fb97941227f38f21',
        'app/Http/Controllers/Hr/Concerns/ResolvesHrTenant.php|tenant_storage_or_usage|13|61ea4714296be36c',
        'app/Http/Controllers/It/Concerns/StoresItAttachments.php|tenant_storage_or_usage|1|d274462ba31e6406',
        'app/Http/Controllers/It/ItKbController.php|tenant_storage_or_usage|2|3f1b89b176efe627',
        'app/Http/Controllers/It/ItProvisioningController.php|tenant_storage_or_usage|4|0b3ba1cda455f595',
        'app/Http/Controllers/It/ItTicketController.php|tenant_storage_or_usage|2|75fb1cc8be7afd80',
        'app/Http/Controllers/Settings/ItMailboxOAuthController.php|tenant_storage_or_usage|1|40af267c79d5309c',
        'app/Http/Middleware/RecordItApiRequest.php|organisation_comparison|2|a17c4566b8ddf75e',
        'app/Http/Middleware/RecordItApiRequest.php|tenant_storage_or_usage|4|54a3f00b0661a185',
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
        'app/Models/ItKbArticle.php|tenant_parameter|2|5b43ba058652571d',
        'app/Models/ItKbArticle.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/ItKbArticle.php|tenant_storage_or_usage|1|836a829ebef78cf7',
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
        'app/Models/ItTeam.php|tenant_parameter|2|a540e8e58df557ce',
        'app/Models/ItTeam.php|tenant_query_scope|1|ff1a1800f4b43f9e',
        'app/Models/ItTeam.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'app/Models/ItTicket.php|tenant_parameter|2|5b43ba058652571d',
        'app/Models/ItTicket.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/ItTicket.php|tenant_storage_or_usage|1|836a829ebef78cf7',
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
        'app/Models/SiteRoom.php|tenant_parameter|3|0a26eeb7f7f2d8aa',
        'app/Models/SiteRoom.php|tenant_query_scope|1|d6336fc18d0750f5',
        'app/Models/SiteRoom.php|tenant_storage_or_usage|1|836a829ebef78cf7',
        'docs/security-devices-restructure-plan.md|tenant_product_copy|3|26c1108221219204',
        'docs/security-devices-restructure-plan.md|tenant_secret_contract|1|fb2385b298e131cc',
        'docs/security-devices-restructure-plan.md|tenant_storage_or_usage|4|8a48232e6913d7bc',
        'docs/superpowers/plans/2026-07-18-it-support-service-management-expansion.md|tenant_product_copy|3|fe82340ddf72e6b5',
        'tests/Feature/It/ItChangeManagementTest.php|tenant_storage_or_usage|3|acc0ddd7368febc2',
        'tests/Feature/It/ItEmailInboundTest.php|organisation_comparison|3|4a4e9d3888d0bc22',
        'tests/Feature/It/ItEmailInboundTest.php|tenant_storage_or_usage|5|30beb2b2ca576323',
        'tests/Feature/It/ItIngressContextAccessTest.php|tenant_storage_or_usage|6|eb00cf14bbbc25fb',
        'tests/Feature/It/ItKbTest.php|tenant_storage_or_usage|3|7eadc38229554333',
        'tests/Feature/It/ItMailboxConnectionTest.php|tenant_storage_or_usage|1|fe0ecf220b5bc442',
        'tests/Feature/It/ItMailboxPollTest.php|organisation_comparison|2|12bca6c1218d2955',
        'tests/Feature/It/ItMailboxPollTest.php|tenant_storage_or_usage|2|37e583be1566a43f',
        'tests/Feature/It/ItMailboxSettingsTest.php|organisation_comparison|1|5ee49190f2392f41',
        'tests/Feature/It/ItMailboxSettingsTest.php|tenant_storage_or_usage|1|47df747d13fc36a0',
        'tests/Feature/It/ItMajorIncidentManagementTest.php|tenant_storage_or_usage|3|c81228e8435f0a54',
        'tests/Feature/It/ItProblemManagementTest.php|tenant_storage_or_usage|3|eb649e09315328ce',
        'tests/Feature/It/ItProvisioningBulkTest.php|tenant_storage_or_usage|10|5327263fefb274bf',
        'tests/Feature/It/ItProvisioningTest.php|tenant_storage_or_usage|15|f164fc351cc7b896',
        'tests/Feature/It/ItProvisioningWorkflowTest.php|tenant_storage_or_usage|14|4a3da12a0f030cd7',
        'tests/Feature/It/ItReportsTest.php|tenant_storage_or_usage|6|86bb280251f1b5c1',
        'tests/Feature/It/ItSecureApiAccessBoundaryTest.php|organisation_comparison|1|90d7d7480075cec6',
        'tests/Feature/It/ItSecureApiTest.php|tenant_storage_or_usage|1|b9bef80b26ef21c4',
        'tests/Feature/It/ItServiceCatalogTest.php|tenant_storage_or_usage|5|c0351ff7943019b6',
        'tests/Feature/It/ItServiceManagementSchemaTest.php|tenant_storage_or_usage|11|495f6bcd6788968e',
        'tests/Feature/It/ItServiceManagementSetupTest.php|tenant_storage_or_usage|8|35c1a7d9b9223a4f',
        'tests/Feature/It/ItServiceOperationsTest.php|tenant_storage_or_usage|30|08aa427641362540',
        'tests/Feature/It/ItSlaCommandTest.php|tenant_storage_or_usage|1|4b7723cdb7abccbf',
        'tests/Feature/It/ItSlaTest.php|tenant_storage_or_usage|7|f338fe23dba299f8',
        'tests/Feature/It/ItTicketApprovalTest.php|tenant_storage_or_usage|5|ee4cd86ebad24f84',
        'tests/Feature/It/ItTicketAuthzTest.php|tenant_storage_or_usage|4|6407b950f9f98d28',
        'tests/Feature/It/ItTicketMergeTest.php|tenant_storage_or_usage|1|a2dd300331628f29',
        'tests/Feature/It/ItTicketWorkspaceTest.php|tenant_storage_or_usage|10|ae07ff2e242ba251',
        'tests/Feature/It/ItTicketingSchemaTest.php|tenant_storage_or_usage|4|60566d1630100166',
        'tests/Feature/It/ItWorkAccessServiceTest.php|tenant_storage_or_usage|4|c3d820b7596af6f5',
        'tests/Feature/It/ItWorkTaskTest.php|organisation_comparison|1|e6c2902028ad83f8',
        'tests/Feature/It/ItWorkTaskTest.php|tenant_storage_or_usage|10|0cc731f580925d6b',
        'tests/Feature/It/ItWorkTransitionTest.php|organisation_comparison|2|f66fa140beb45615',
        'tests/Feature/It/ItWorkTransitionTest.php|tenant_storage_or_usage|4|1190c6bfc93ae790',
        'tests/Feature/Monitoring/MonitoringDeliveryTest.php|organisation_comparison|3|25790f063ee2f159',
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
        'tests/Feature/SecurityDevices/DeviceControllerTest.php|tenant_storage_or_usage|12|4212bcd173f93edc',
        'tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php|tenant_storage_or_usage|6|8d87366e24c36e57',
        'tests/Feature/SecurityDevices/DeviceGroupAutoRulesTest.php|tenant_storage_or_usage|4|cc248c134096f1f3',
        'tests/Feature/SecurityDevices/DeviceGroupControllerTest.php|organisation_comparison|1|a582cfcc70cd514b',
        'tests/Feature/SecurityDevices/DeviceGroupControllerTest.php|tenant_storage_or_usage|19|e0bc79d4d2da2914',
        'tests/Feature/SecurityDevices/DeviceMutationAccessBoundaryTest.php|organisation_comparison|13|771f3b65883c17b0',
        'tests/Feature/SecurityDevices/DeviceMutationAccessBoundaryTest.php|tenant_storage_or_usage|13|5c7517982f437492',
        'tests/Feature/SecurityDevices/DeviceProfileWorkspaceTest.php|organisation_comparison|5|d6d59a0d4d2d055c',
        'tests/Feature/SecurityDevices/DeviceProfileWorkspaceTest.php|tenant_storage_or_usage|37|f4bbc0b8a64a8ee0',
        'tests/Feature/SecurityDevices/EstateSiteOperationsTest.php|organisation_comparison|2|e6cc07658fe65c89',
        'tests/Feature/SecurityDevices/EstateSiteOperationsTest.php|tenant_storage_or_usage|32|dff0cb0113e5517a',
        'tests/Feature/SecurityDevices/FacilitiesWorkspaceTest.php|organisation_comparison|1|e6b46a9c9c6e97f2',
        'tests/Feature/SecurityDevices/FacilitiesWorkspaceTest.php|tenant_storage_or_usage|8|d57c43973fff4397',
        'tests/Feature/SecurityDevices/FinanceDeviceHealthTest.php|organisation_comparison|6|33e19d5a64cf8f93',
        'tests/Feature/SecurityDevices/HealthcareWorkspaceTest.php|organisation_comparison|10|27dbe9b4a9d84f1e',
        'tests/Feature/SecurityDevices/HealthcareWorkspaceTest.php|tenant_storage_or_usage|10|a7356af9053cc696',
        'tests/Feature/SecurityDevices/IntegrationsHubTest.php|organisation_comparison|8|a4b179725fb7a674',
        'tests/Feature/SecurityDevices/IntegrationsHubTest.php|tenant_storage_or_usage|47|dcb82e93744c5e2f',
        'tests/Feature/SecurityDevices/LocationHardwareRetirementTest.php|organisation_comparison|3|932760977f978de9',
        'tests/Feature/SecurityDevices/LocationHardwareRetirementTest.php|tenant_storage_or_usage|3|3820974871b01211',
        'tests/Feature/SecurityDevices/MaintenanceHealthControllerTest.php|organisation_comparison|2|b3653054df14f926',
        'tests/Feature/SecurityDevices/MaintenanceHealthControllerTest.php|tenant_storage_or_usage|3|46d74129a8aa27a1',
        'tests/Feature/SecurityDevices/NetworkItWorkspaceTest.php|organisation_comparison|1|e6b46a9c9c6e97f2',
        'tests/Feature/SecurityDevices/NetworkItWorkspaceTest.php|tenant_storage_or_usage|11|5be666744fea24fc',
        'tests/Feature/SecurityDevices/NonUnifiHealthPullMigrationTest.php|tenant_storage_or_usage|13|1745d9aabf283aa5',
        'tests/Feature/SecurityDevices/OperationsWorkspacesTest.php|organisation_comparison|2|e6cc07658fe65c89',
        'tests/Feature/SecurityDevices/OperationsWorkspacesTest.php|tenant_storage_or_usage|28|3bec1c3bd809fcec',
        'tests/Feature/SecurityDevices/ProviderConnectionsSingleApplicationTest.php|organisation_comparison|1|6ee6cd6d3cfb5cf3',
        'tests/Feature/SecurityDevices/ProviderConnectionsSingleApplicationTest.php|tenant_secret_contract|1|b4629a8c04318801',
        'tests/Feature/SecurityDevices/ProviderConnectionsSingleApplicationTest.php|tenant_storage_or_usage|29|5d3db9fdbbfb4f65',
        'tests/Feature/SecurityDevices/ReportsExportTest.php|organisation_comparison|1|0a88791b4d93bc24',
        'tests/Feature/SecurityDevices/ReportsExportTest.php|tenant_storage_or_usage|3|de5657f746cfe208',
        'tests/Feature/SecurityDevices/ResidentTrackingRefactorTest.php|organisation_comparison|3|8b44778786b70bcf',
        'tests/Feature/SecurityDevices/ResidentTrackingRefactorTest.php|tenant_storage_or_usage|11|706683e2c309c085',
        'tests/Feature/SecurityDevices/SecurityDevicesNavigationRoutesTest.php|organisation_comparison|2|3d3324ba87f20af8',
        'tests/Feature/SecurityDevices/SecurityDevicesNavigationRoutesTest.php|tenant_product_copy|2|71774f77930b24d7',
        'tests/Feature/SecurityDevices/SecurityDevicesNavigationRoutesTest.php|tenant_storage_or_usage|7|f71b3e4a0309e330',
        'tests/Feature/SecurityDevices/SecurityDevicesSingleApplicationAccessTest.php|organisation_comparison|1|b6fd9ff72f04a257',
        'tests/Feature/SecurityDevices/SecurityDevicesSingleApplicationAccessTest.php|tenant_storage_or_usage|7|a4b4a8b55279a6dd',
        'tests/Feature/SecurityDevices/SecurityWorkspaceTest.php|organisation_comparison|2|e6cc07658fe65c89',
        'tests/Feature/SecurityDevices/SecurityWorkspaceTest.php|tenant_storage_or_usage|16|89cbbe883329dbb5',
        'tests/Feature/SecurityDevices/SettingsAuditTest.php|organisation_comparison|51|819162af55da2ce8',
        'tests/Feature/SecurityDevices/SettingsAuditTest.php|tenant_storage_or_usage|105|2fd7243bc9e39951',
        'tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php|tenant_product_copy|1|7a2ee69e882e8e65',
        'tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php|tenant_storage_or_usage|32|81c973098d84f28c',
        'tests/Feature/SecurityDevices/TrackingWorkspaceTest.php|organisation_comparison|7|895a76ea18fd29ec',
        'tests/Feature/SecurityDevices/TrackingWorkspaceTest.php|tenant_storage_or_usage|4|ff84ee8ec9cb5217',
        'tests/Feature/SecurityDevices/UnifiDiscoveryFailureTest.php|organisation_comparison|1|9c89a450fe1403ee',
        'tests/Feature/SecurityDevices/UnifiDiscoveryFailureTest.php|tenant_storage_or_usage|2|08be15f39db8f354',
        'tests/Feature/SecurityDevices/UnifiOperationalBridgeMigrationTest.php|tenant_storage_or_usage|26|e8aa98de26180184',
        'tests/Feature/SecurityDevices/UnifiSettingsRefactorTest.php|tenant_storage_or_usage|54|50e35d1ef4bd0590',
        'tests/Feature/SecurityDevices/WorkspaceCompatibilityTest.php|organisation_comparison|2|984bfa76ef518805',
        'tests/Feature/SecurityDevices/WorkspaceCompatibilityTest.php|tenant_storage_or_usage|3|f13675f734cafcc0',
        'tests/Unit/SecurityDevices/DeviceModelTest.php|tenant_query_scope|2|a72cf3d104dfc86e',
        'tests/Unit/SecurityDevices/DeviceModelTest.php|tenant_storage_or_usage|5|6b784b2814591eb2',
        'tests/Unit/SecurityDevices/DeviceRegistryServiceTest.php|tenant_storage_or_usage|4|835ece265234b1d5',
        'tests/Unit/SecurityDevices/MigrateDevicesCommandTest.php|tenant_storage_or_usage|2|e80dccfe197dc693',
    ];
}
