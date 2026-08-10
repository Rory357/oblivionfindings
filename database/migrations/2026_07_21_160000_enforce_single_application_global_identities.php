<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertGlobalIdentityCollisionsAreResolved();
        $this->synchroniseTicketReferenceSequence();

        // Every replacement query index is present before its legacy-leading
        // counterpart is removed. Global unique indexes are also added before
        // the narrower compatibility identities are dropped.
        foreach ($this->indexTransitions() as $transition) {
            $this->addIndex($transition);
        }
        foreach ($this->identityTransitions() as $transition) {
            if ($transition['new'] === $transition['old']) {
                $this->replaceIndexInPlace(
                    $transition['table'],
                    $transition['new'],
                    $transition['columns'],
                    true,
                );
            } else {
                $this->addIndex($transition);
            }
        }

        foreach ($this->identityTransitions() as $transition) {
            if ($transition['new'] !== $transition['old']) {
                $this->dropIndex($transition['table'], $transition['old'], true);
            }
        }
        foreach ($this->indexTransitions() as $transition) {
            $this->dropIndex($transition['table'], $transition['old'], false);
        }
        foreach ($this->dropOnlyIndexes() as [$table, $index]) {
            $this->dropIndex($table, $index, false);
        }
    }

    public function down(): void
    {
        foreach ($this->identityTransitions() as $transition) {
            if ($transition['new'] === $transition['old']) {
                $this->replaceIndexInPlace(
                    $transition['table'],
                    $transition['old'],
                    $transition['legacy_columns'],
                    true,
                );

                continue;
            }

            $legacy = [
                'table' => $transition['table'],
                'columns' => $transition['legacy_columns'],
                'new' => $transition['old'],
                'unique' => true,
            ];
            $this->addIndex($legacy);
        }
        foreach ($this->indexTransitions() as $transition) {
            $legacy = [
                'table' => $transition['table'],
                'columns' => $transition['legacy_columns'],
                'new' => $transition['old'],
                'unique' => false,
            ];
            $this->addIndex($legacy);
        }
        foreach ($this->dropOnlyIndexes() as [$table, $index, $columns]) {
            $this->addIndex([
                'table' => $table,
                'columns' => $columns,
                'new' => $index,
                'unique' => false,
            ]);
        }

        foreach ($this->identityTransitions() as $transition) {
            if ($transition['new'] !== $transition['old']) {
                $this->dropIndex($transition['table'], $transition['new'], true);
            }
        }
        foreach ($this->indexTransitions() as $transition) {
            $this->dropIndex($transition['table'], $transition['new'], false);
        }
    }

    private function assertGlobalIdentityCollisionsAreResolved(): void
    {
        $collisions = [];

        foreach ($this->identityTransitions() as $transition) {
            $table = $transition['table'];
            $columns = $transition['columns'];
            if (! $this->tableHasColumns($table, $columns)) {
                continue;
            }

            $query = DB::table($table)->select($columns);
            foreach ($transition['required'] as $column) {
                $query->whereNotNull($column);
            }

            if ($query->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
                $collisions[] = $transition['label'];
            }
        }

        if ($collisions !== []) {
            throw new RuntimeException(
                'Global application identity collisions require reconciliation before migration: '
                .implode(', ', $collisions).'.',
            );
        }
    }

    private function synchroniseTicketReferenceSequence(): void
    {
        if (! $this->tableHasColumns('it_tickets', ['reference'])
            || ! $this->tableHasColumns('reference_sequences', ['scope', 'next_value'])
        ) {
            return;
        }

        $substring = DB::getDriverName() === 'sqlite'
            ? 'SUBSTR(reference, 4)'
            : 'SUBSTRING(reference, 4)';
        $max = (int) DB::table('it_tickets')
            ->whereNotNull('reference')
            ->selectRaw("MAX(CAST({$substring} AS UNSIGNED)) AS seq")
            ->value('seq');
        $floor = $max + 1;

        DB::table('reference_sequences')->insertOrIgnore([
            'scope' => 'IT',
            'next_value' => $floor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reference_sequences')
            ->where('scope', 'IT')
            ->where('next_value', '<', $floor)
            ->update(['next_value' => $floor, 'updated_at' => now()]);
    }

    /**
     * @param  array{table: string, columns: list<string>, new: string|null, unique: bool}  $transition
     */
    private function addIndex(array $transition): void
    {
        $table = $transition['table'];
        $index = $transition['new'];
        if ($index === null
            || ! $this->tableHasColumns($table, $transition['columns'])
            || Schema::hasIndex($table, $index)
        ) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($transition, $index): void {
            if ($transition['unique']) {
                $blueprint->unique($transition['columns'], $index);

                return;
            }

            $blueprint->index($transition['columns'], $index);
        });
    }

    private function dropIndex(string $table, ?string $index, bool $unique): void
    {
        if ($index === null || ! Schema::hasTable($table) || ! Schema::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index, $unique): void {
            if ($unique) {
                $blueprint->dropUnique($index);

                return;
            }

            $blueprint->dropIndex($index);
        });
    }

    /** @param list<string> $columns */
    private function replaceIndexInPlace(
        string $table,
        string $index,
        array $columns,
        bool $unique,
    ): void {
        if (! $this->tableHasColumns($table, $columns)) {
            return;
        }

        $existing = collect(Schema::getIndexes($table))->firstWhere('name', $index);
        if (($existing['columns'] ?? null) === $columns
            && (bool) ($existing['unique'] ?? false) === $unique
        ) {
            return;
        }

        if ($existing === null) {
            $this->addIndex([
                'table' => $table,
                'columns' => $columns,
                'new' => $index,
                'unique' => $unique,
            ]);

            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $grammar = DB::connection()->getQueryGrammar();
            $wrappedTable = $grammar->wrapTable($table);
            $wrappedIndex = $grammar->wrap($index);
            $wrappedColumns = implode(', ', array_map(
                fn (string $column): string => $grammar->wrap($column),
                $columns,
            ));
            $indexType = $unique ? 'UNIQUE INDEX' : 'INDEX';

            // One MySQL ALTER keeps the constraint name continuously usable.
            // Queue workers from the previous release therefore recognize and
            // retry reference collisions while this release is rolling out.
            DB::statement(
                "ALTER TABLE {$wrappedTable} DROP INDEX {$wrappedIndex}, "
                ."ADD {$indexType} {$wrappedIndex} ({$wrappedColumns})",
            );

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index, $unique): void {
            if ($unique) {
                $blueprint->dropUnique($index);
            } else {
                $blueprint->dropIndex($index);
            }
        });
        $this->addIndex([
            'table' => $table,
            'columns' => $columns,
            'new' => $index,
            'unique' => $unique,
        ]);
    }

    /** @param list<string> $columns */
    private function tableHasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{
     *   label: string,
     *   table: string,
     *   columns: list<string>,
     *   required: list<string>,
     *   new: string,
     *   old: string,
     *   legacy_columns: list<string>,
     *   unique: true
     * }>
     */
    private function identityTransitions(): array
    {
        return [
            // Preserve the established constraint name while atomically
            // changing its columns. Pre-release queue workers key their
            // collision retry to this name during rolling deployments.
            $this->identity('ticket reference', 'it_tickets', ['reference'], ['reference'], 'it_tickets_tenant_reference_uq', 'it_tickets_tenant_reference_uq'),
            $this->identity('SLA priority', 'it_sla_policies', ['priority'], ['priority'], 'it_sla_policies_priority_uq', 'it_sla_policies_tenant_priority_uq'),
            $this->identity('knowledge article slug', 'it_kb_articles', ['slug'], ['slug'], 'it_kb_articles_slug_uq', 'it_kb_articles_tenant_slug_uq'),
            $this->identity('mailbox provider', 'it_mailbox_connections', ['provider'], ['provider'], 'it_mailbox_connections_provider_uq', 'it_mailbox_connections_tenant_id_provider_unique'),
            $this->identity('IT team name', 'it_teams', ['name'], ['name'], 'it_teams_name_uq', 'it_teams_tenant_name_uq'),
            $this->identity('IT queue key', 'it_queues', ['key'], ['key'], 'it_queues_key_uq', 'it_queues_tenant_key_uq'),
            $this->identity('IT service key', 'it_services', ['key'], ['key'], 'it_services_key_uq', 'it_services_tenant_key_uq'),
            $this->identity('catalogue item slug', 'it_catalog_items', ['slug'], ['slug'], 'it_catalog_items_slug_uq', 'it_catalog_items_tenant_slug_uq'),
            $this->identity(
                'catalogue requester idempotency',
                'it_catalog_submissions',
                ['requester_user_id', 'idempotency_key'],
                ['requester_user_id', 'idempotency_key'],
                'it_catalog_submissions_requester_idempotency_uq',
                'it_catalog_submissions_idempotency_uq',
            ),
            $this->identity(
                'provisioning source event',
                'it_provisioning_workflows',
                ['source_event_key'],
                ['source_event_key'],
                'it_prov_workflows_source_event_uq',
                'it_prov_workflows_tenant_event_uq',
            ),
            $this->identity('Device Group name', 'device_groups', ['name'], ['name'], 'device_groups_name_uq', 'dev_groups_tenant_name_unique'),
            $this->identity('integration registry provider', 'integrations', ['provider'], ['provider'], 'integrations_provider_uq', 'integrations_tenant_id_provider_unique'),
            $this->identity(
                'integration provider event',
                'integration_events',
                ['provider', 'source_event_id'],
                ['provider', 'source_event_id'],
                'integration_events_provider_source_event_uq',
                'integration_events_tenant_provider_source_event_unique',
            ),
            $this->identity('Queclink preset slug', 'queclink_presets', ['slug'], ['slug'], 'queclink_presets_slug_uq', 'queclink_presets_tenant_id_slug_unique'),
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $required
     * @return array{
     *   label: string,
     *   table: string,
     *   columns: list<string>,
     *   required: list<string>,
     *   new: string,
     *   old: string,
     *   legacy_columns: list<string>,
     *   unique: true
     * }
     */
    private function identity(
        string $label,
        string $table,
        array $columns,
        array $required,
        string $new,
        string $old,
    ): array {
        return [
            'label' => $label,
            'table' => $table,
            'columns' => $columns,
            'required' => $required,
            'new' => $new,
            'old' => $old,
            'legacy_columns' => ['tenant_id', ...$columns],
            'unique' => true,
        ];
    }

    /**
     * @return list<array{
     *   table: string,
     *   columns: list<string>,
     *   new: string|null,
     *   old: string,
     *   legacy_columns: list<string>,
     *   unique: false
     * }>
     */
    private function indexTransitions(): array
    {
        return [
            $this->index('it_provisioning_requests', ['status'], 'it_prov_requests_status_idx', 'it_prov_requests_tenant_status_idx'),
            $this->index('it_tickets', ['status'], 'it_tickets_status_idx', 'it_tickets_tenant_status_idx'),
            $this->index('it_tickets', ['sla_state'], 'it_tickets_sla_state_idx', 'it_tickets_tenant_sla_state_idx'),
            $this->index('it_tickets', ['assigned_to_user_id', 'status'], 'it_tickets_assignee_status_idx', 'it_tickets_tenant_assignee_status_idx'),
            $this->index('it_tickets', ['work_type', 'status'], 'it_tickets_type_status_idx', 'it_tickets_tenant_type_status_idx'),
            $this->index('it_tickets', ['queue_id', 'status'], 'it_tickets_queue_status_idx', 'it_tickets_tenant_queue_status_idx'),
            $this->index('it_tickets', ['team_id', 'status'], 'it_tickets_team_status_idx', 'it_tickets_tenant_team_status_idx'),
            $this->index('it_tickets', ['it_service_id', 'status'], 'it_tickets_service_status_idx', 'it_tickets_tenant_service_status_idx'),
            $this->index('it_tickets', ['work_type', 'workflow_state'], 'it_tickets_type_workflow_idx', 'it_tickets_tenant_type_workflow_idx'),
            $this->index('it_ticket_links', ['linkable_type', 'linkable_id'], 'it_ticket_links_target_idx', 'it_ticket_links_tenant_target_idx'),
            $this->index('it_kb_articles', ['status'], 'it_kb_articles_status_idx', 'it_kb_articles_tenant_status_idx'),
            $this->index('it_kb_articles', ['audience', 'status'], 'it_kb_articles_audience_status_idx', 'it_kb_articles_tenant_audience_idx'),
            $this->index('it_kb_articles', ['review_due_at'], 'it_kb_articles_review_due_global_idx', 'it_kb_articles_review_due_idx'),
            $this->index('it_queues', ['team_id', 'is_active'], 'it_queues_team_active_idx', 'it_queues_tenant_team_active_idx'),
            $this->index('it_services', ['status', 'is_active'], 'it_services_status_active_idx', 'it_services_tenant_status_active_idx'),
            $this->index('it_work_tasks', ['ticket_id', 'status'], 'it_work_tasks_ticket_status_idx', 'it_work_tasks_tenant_ticket_status_idx'),
            $this->index('it_work_tasks', ['team_id', 'status'], 'it_work_tasks_team_status_idx', 'it_work_tasks_tenant_team_status_idx'),
            $this->index('it_work_tasks', ['assigned_to_user_id', 'status'], 'it_work_tasks_assignee_status_idx', 'it_work_tasks_tenant_assignee_status_idx'),
            $this->index('it_catalog_items', ['is_published', 'sort_order'], 'it_catalog_items_discovery_global_idx', 'it_catalog_items_discovery_idx'),
            $this->index('it_catalog_submissions', ['catalog_item_id', 'submitted_at'], 'it_catalog_submissions_item_global_idx', 'it_catalog_submissions_item_idx'),
            $this->index('it_service_identities', ['revoked_at', 'expires_at'], 'it_service_identities_active_global_idx', 'it_service_identities_active_idx'),
            $this->index('it_api_requests', ['service_identity_id', 'created_at'], 'it_api_requests_identity_created_global_idx', 'it_api_requests_tenant_identity_created_idx'),
            $this->index('it_problems', ['known_error_at'], 'it_problems_known_error_idx', 'it_problems_tenant_known_error_idx'),
            $this->index('it_changes', ['change_type', 'risk_level'], 'it_changes_type_risk_idx', 'it_changes_tenant_type_risk_idx'),
            $this->index('it_changes', ['maintenance_starts_at'], 'it_changes_window_idx', 'it_changes_tenant_window_idx'),
            $this->index('it_major_incidents', ['severity', 'next_update_due_at'], 'it_major_incidents_cadence_global_idx', 'it_major_incidents_cadence_idx'),
            $this->index('it_major_incident_updates', ['major_incident_id', 'published_at'], 'it_major_updates_timeline_idx', 'it_major_incident_updates_timeline_idx'),
            $this->index('it_major_incident_updates', ['audience', 'published_at'], 'it_major_updates_audience_idx', 'it_major_incident_updates_audience_idx'),
            $this->index('it_provisioning_templates', ['lifecycle_type', 'is_active'], 'it_prov_templates_lifecycle_active_idx', 'it_prov_templates_tenant_lifecycle_active_idx'),
            $this->index('it_provisioning_workflows', ['lifecycle_type', 'status'], 'it_prov_workflows_lifecycle_status_idx', 'it_prov_workflows_tenant_lifecycle_status_idx'),
            $this->index('it_kb_interactions', ['it_kb_article_id', 'event_type'], 'it_kb_interactions_article_event_global_idx', 'it_kb_interactions_article_event_idx'),
            $this->index('it_kb_interactions', ['user_id', 'occurred_at'], 'it_kb_interactions_user_time_global_idx', 'it_kb_interactions_user_time_idx'),
            $this->index('it_email_deliveries', ['status', 'created_at'], 'it_email_deliveries_status_global_idx', 'it_email_deliveries_status_idx'),
            $this->index('it_email_deliveries', ['it_ticket_id', 'created_at'], 'it_email_deliveries_ticket_global_idx', 'it_email_deliveries_ticket_idx'),
            $this->index('it_email_deliveries', ['it_provisioning_request_id', 'created_at'], 'it_email_deliveries_provisioning_global_idx', 'it_email_deliveries_provisioning_idx'),
            $this->index('devices', ['domain', 'status'], 'devices_domain_status_idx', 'devices_tenant_domain_status_idx'),
            $this->index('devices', ['category', 'status'], 'devices_category_status_idx', 'devices_tenant_category_status_idx'),
            $this->index('devices', ['provider'], 'devices_provider_idx', 'devices_tenant_provider_idx'),
            $this->index('devices', ['health_status'], 'devices_health_idx', 'devices_tenant_health_idx'),
            $this->index('integration_sync_logs', ['provider'], 'integration_sync_logs_provider_idx', 'integration_sync_logs_tenant_id_provider_index'),
            $this->index('integration_events', ['site_id', 'occurred_at'], 'integration_events_site_occurred_idx', 'integration_events_tenant_id_site_id_occurred_at_index'),
            $this->index('integration_alerts', ['status', 'severity'], 'integration_alerts_status_severity_idx', 'integration_alerts_tenant_id_status_severity_index'),
            $this->index('queclink_audit_events', ['created_at'], 'queclink_audit_events_created_idx', 'queclink_audit_events_tenant_id_created_at_index'),
            $this->index('queclink_presets', ['is_system'], 'queclink_presets_system_idx', 'queclink_presets_tenant_id_is_system_index'),
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return array{
     *   table: string,
     *   columns: list<string>,
     *   new: string,
     *   old: string,
     *   legacy_columns: list<string>,
     *   unique: false
     * }
     */
    private function index(string $table, array $columns, string $new, string $old): array
    {
        return [
            'table' => $table,
            'columns' => $columns,
            'new' => $new,
            'old' => $old,
            'legacy_columns' => ['tenant_id', ...$columns],
            'unique' => false,
        ];
    }

    /** @return list<array{0: string, 1: string, 2: list<string>}> */
    private function dropOnlyIndexes(): array
    {
        return [
            ['it_provisioning_requests', 'it_provisioning_requests_tenant_id_index', ['tenant_id']],
            ['it_tickets', 'it_tickets_tenant_id_index', ['tenant_id']],
            ['it_attachments', 'it_attachments_tenant_id_index', ['tenant_id']],
            ['it_ticket_comments', 'it_ticket_comments_tenant_id_index', ['tenant_id']],
            ['it_ticket_events', 'it_ticket_events_tenant_id_index', ['tenant_id']],
            ['it_ticket_approvals', 'it_ticket_approvals_tenant_id_index', ['tenant_id']],
            ['it_inbound_emails', 'it_inbound_emails_tenant_id_index', ['tenant_id']],
            ['it_mailbox_connections', 'it_mailbox_connections_tenant_id_index', ['tenant_id']],
            ['it_teams', 'it_teams_tenant_id_index', ['tenant_id']],
            ['it_queues', 'it_queues_tenant_id_index', ['tenant_id']],
            ['it_services', 'it_services_tenant_id_index', ['tenant_id']],
            ['it_work_tasks', 'it_work_tasks_tenant_id_index', ['tenant_id']],
            ['it_service_identities', 'it_service_identities_tenant_id_index', ['tenant_id']],
            ['it_api_requests', 'it_api_requests_tenant_id_index', ['tenant_id']],
            ['it_problems', 'it_problems_tenant_id_index', ['tenant_id']],
            ['it_changes', 'it_changes_tenant_id_index', ['tenant_id']],
            ['it_major_incidents', 'it_major_incidents_tenant_id_index', ['tenant_id']],
            ['it_major_incident_updates', 'it_major_incident_updates_tenant_id_index', ['tenant_id']],
            ['it_provisioning_templates', 'it_provisioning_templates_tenant_id_index', ['tenant_id']],
            ['it_provisioning_workflows', 'it_provisioning_workflows_tenant_id_index', ['tenant_id']],
            ['it_kb_interactions', 'it_kb_interactions_tenant_id_index', ['tenant_id']],
            ['it_email_deliveries', 'it_email_deliveries_tenant_id_index', ['tenant_id']],
            ['it_automation_runs', 'it_automation_runs_tenant_id_index', ['tenant_id']],
            ['devices', 'devices_tenant_id_index', ['tenant_id']],
            ['device_groups', 'device_groups_tenant_id_index', ['tenant_id']],
            ['integrations', 'integrations_tenant_id_index', ['tenant_id']],
            ['integration_tenant_secrets', 'integration_tenant_secrets_tenant_id_index', ['tenant_id']],
            ['integration_site_configs', 'integration_site_configs_tenant_id_index', ['tenant_id']],
            ['integration_site_secrets', 'integration_site_secrets_tenant_id_index', ['tenant_id']],
            ['integration_sync_logs', 'integration_sync_logs_tenant_id_index', ['tenant_id']],
            ['integration_events', 'integration_events_tenant_id_index', ['tenant_id']],
            ['integration_alerts', 'integration_alerts_tenant_id_index', ['tenant_id']],
            ['site_rooms', 'site_rooms_tenant_id_index', ['tenant_id']],
            ['location_hardware', 'location_hardware_tenant_id_index', ['tenant_id']],
            ['queclink_devices', 'queclink_devices_tenant_id_index', ['tenant_id']],
            ['queclink_raw_frames', 'queclink_raw_frames_tenant_id_created_at_index', ['tenant_id', 'created_at']],
        ];
    }
};
