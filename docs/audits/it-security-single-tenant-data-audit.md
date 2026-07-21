# IT, Security & Devices single-tenant data audit

> Source: local development database snapshot. This is not production state.

> This is read-only, redacted Task 1 no-regression evidence. It does not prove single-tenant remediation is complete.

## Summary

| Check | Count |
| --- | ---: |
| legacy id values | 1 |
| legacy null rows | 0 |
| unavailable legacy id sources | 15 |
| global key collision groups | 0 |
| global key collision rows | 0 |
| provenance findings | 0 |
| orphan findings | 0 |
| null site tickets | 0 |
| unassigned devices | 0 |
| ambiguously assigned devices | 0 |
| tenant leading indexes | 37 |
| unavailable checks | 17 |

## Legacy boundary identifiers

| Fingerprint | Rows | Sources | Tables |
| --- | ---: | ---: | ---: |
| fp:2fbb68cf10606033e6f7 | 74 | 10 | 10 |

## Legacy boundary sources

| Source | Status | Rows | Null rows | Distinct values |
| --- | --- | ---: | ---: | ---: |
| assets.organization_id | not_available | 0 | 0 | 0 |
| assets.tenant_id | not_available | 0 | 0 | 0 |
| clients.organization_id | audited | 0 | 0 | 0 |
| clients.tenant_id | not_available | 0 | 0 | 0 |
| device_asset_links.tenant_id | not_available | 0 | 0 | 0 |
| device_assignments.tenant_id | not_available | 0 | 0 | 0 |
| device_documents.tenant_id | not_available | 0 | 0 | 0 |
| device_events.tenant_id | not_available | 0 | 0 | 0 |
| device_group_members.tenant_id | not_available | 0 | 0 | 0 |
| device_groups.tenant_id | audited | 0 | 0 | 0 |
| device_maintenance_records.tenant_id | not_available | 0 | 0 | 0 |
| device_relationships.tenant_id | not_available | 0 | 0 | 0 |
| devices.tenant_id | audited | 3 | 0 | 1 |
| hr_assets.organization_id | not_available | 0 | 0 | 0 |
| hr_assets.tenant_id | audited | 0 | 0 | 0 |
| hr_employee_profiles.organization_id | not_available | 0 | 0 | 0 |
| hr_employee_profiles.tenant_id | audited | 14 | 0 | 1 |
| integration_alerts.tenant_id | audited | 0 | 0 | 0 |
| integration_events.tenant_id | audited | 0 | 0 | 0 |
| integration_site_configs.tenant_id | audited | 0 | 0 | 0 |
| integration_site_secrets.tenant_id | audited | 0 | 0 | 0 |
| integration_sync_logs.tenant_id | audited | 0 | 0 | 0 |
| integration_tenant_secrets.tenant_id | audited | 0 | 0 | 0 |
| integrations.tenant_id | audited | 0 | 0 | 0 |
| it_provisioning_requests.tenant_id | audited | 0 | 0 | 0 |
| it_ticket_comments.tenant_id | audited | 0 | 0 | 0 |
| it_ticket_events.tenant_id | audited | 0 | 0 | 0 |
| it_ticket_links.tenant_id | audited | 1 | 0 | 1 |
| it_ticket_watchers.tenant_id | not_available | 0 | 0 | 0 |
| it_tickets.tenant_id | audited | 1 | 0 | 1 |
| location_hardware.tenant_id | audited | 0 | 0 | 0 |
| monitor_observations.tenant_id | audited | 4 | 0 | 1 |
| monitoring_collectors.tenant_id | audited | 1 | 0 | 1 |
| monitoring_profiles.tenant_id | audited | 2 | 0 | 1 |
| monitors.tenant_id | audited | 3 | 0 | 1 |
| site_rooms.tenant_id | audited | 0 | 0 | 0 |
| sites.organization_id | not_available | 0 | 0 | 0 |
| sites.tenant_id | audited | 30 | 0 | 1 |
| users.organization_id | audited | 15 | 0 | 1 |
| users.tenant_id | not_available | 0 | 0 | 0 |

## Global identity checks

| Identity | Table | Status | Duplicate groups | Duplicate rows |
| --- | --- | --- | ---: | ---: |
| ticket_reference | it_tickets | clear | 0 | 0 |
| sla_priority | it_sla_policies | not_available | 0 | 0 |
| kb_slug | it_kb_articles | not_available | 0 | 0 |
| mailbox_provider | it_mailbox_connections | not_available | 0 | 0 |
| team_name | it_teams | not_available | 0 | 0 |
| queue_key | it_queues | not_available | 0 | 0 |
| service_key | it_services | not_available | 0 | 0 |
| catalogue_slug | it_catalog_items | not_available | 0 | 0 |
| catalogue_requester_idempotency | it_catalog_submissions | not_available | 0 | 0 |
| provisioning_source_event | it_provisioning_workflows | not_available | 0 | 0 |
| collector_uuid | monitoring_collectors | clear | 0 | 0 |
| monitoring_profile_name | monitoring_profiles | clear | 0 | 0 |
| device_group_name | device_groups | clear | 0 | 0 |
| integration_provider | integrations | clear | 0 | 0 |
| integration_provider_event | integration_events | clear | 0 | 0 |
| queclink_preset_slug | queclink_presets | not_available | 0 | 0 |

## Inbound email ambiguity

- Inbound email reference ambiguity: clear
- Ambiguous reference groups: 0
- Tickets in ambiguous reference groups: 0

## Provenance checks

| Check | Status | Count |
| --- | --- | ---: |
| ticket_site_legacy_id_mismatch | not_available | 0 |
| ticket_team_legacy_id_mismatch | not_available | 0 |
| ticket_queue_legacy_id_mismatch | not_available | 0 |
| ticket_service_legacy_id_mismatch | not_available | 0 |
| monitor_device_legacy_id_mismatch | clear | 0 |
| monitor_profile_legacy_id_mismatch | clear | 0 |
| monitor_collector_legacy_id_mismatch | clear | 0 |
| collector_site_legacy_id_mismatch | clear | 0 |
| provider_site_mapping_site_legacy_id_mismatch | clear | 0 |
| device_active_site_assignment_conflict | clear | 0 |
| device_assignment_site_legacy_id_mismatch | clear | 0 |
| device_assignment_room_site_legacy_id_mismatch | clear | 0 |
| device_assignment_client_site_legacy_id_mismatch | clear | 0 |
| device_assignment_vehicle_site_legacy_id_mismatch | clear | 0 |
| device_assignment_vehicle_home_site_legacy_id_mismatch | clear | 0 |
| device_assignment_vehicle_client_site_legacy_id_mismatch | clear | 0 |
| vehicle_site_home_site_canonical_conflict | clear | 0 |
| vehicle_site_client_site_canonical_conflict | clear | 0 |
| vehicle_home_site_client_site_canonical_conflict | clear | 0 |
| device_active_canonical_site_conflict | clear | 0 |
| device_assignment_staff_site_legacy_id_mismatch | clear | 0 |

## Orphan checks

| Check | Status | Count |
| --- | --- | ---: |
| device_assignment_site_target_missing | clear | 0 |
| device_assignment_room_target_missing | clear | 0 |
| device_assignment_client_target_missing | clear | 0 |
| device_assignment_staff_target_missing | clear | 0 |
| device_assignment_vehicle_target_missing | clear | 0 |
| device_assignment_unknown_target_type | clear | 0 |
| device_assignment_device_missing | clear | 0 |
| provider_site_mapping_without_connection | clear | 0 |
| provider_site_mapping_site_missing | clear | 0 |
| it_ticket_link_ticket_missing | clear | 0 |
| it_ticket_link_security_device_target_missing | clear | 0 |
| it_ticket_link_control_room_alert_target_missing | clear | 0 |
| it_ticket_link_it_ticket_target_missing | clear | 0 |
| it_ticket_link_it_service_target_missing | not_available | 0 |
| it_ticket_link_site_target_missing | clear | 0 |
| it_ticket_link_unknown_target_type | clear | 0 |
| device_asset_link_device_missing | clear | 0 |
| device_asset_link_asset_missing | clear | 0 |
| monitor_device_missing | clear | 0 |
| monitor_profile_missing | clear | 0 |
| monitor_collector_missing | clear | 0 |

## Null-Site tickets and device assignment posture

- Null-Site ticket audit status: not_available
- Organisation-wide evidence marker: not_available
- Null-Site tickets: 0
- Null-Site tickets with explicit organisation-wide evidence: 0
- Null-Site tickets without explicit organisation-wide evidence: 0
- Device assignment audit status: clear
- Unassigned devices: 0
- Ambiguously assigned devices: 0
- Future-dated assignment rows: 0

## Tenant-leading indexes requiring replacement planning

| Table | Index | Columns | Unique |
| --- | --- | --- | --- |
| device_groups | dev_groups_tenant_name_unique | tenant_id, name | yes |
| device_groups | device_groups_tenant_id_index | tenant_id | no |
| devices | devices_tenant_category_status_idx | tenant_id, category, status | no |
| devices | devices_tenant_domain_status_idx | tenant_id, domain, status | no |
| devices | devices_tenant_health_idx | tenant_id, health_status | no |
| devices | devices_tenant_id_index | tenant_id | no |
| devices | devices_tenant_provider_idx | tenant_id, provider | no |
| integration_alerts | integration_alerts_tenant_id_index | tenant_id | no |
| integration_alerts | integration_alerts_tenant_id_status_severity_index | tenant_id, status, severity | no |
| integration_events | integration_events_tenant_id_index | tenant_id | no |
| integration_events | integration_events_tenant_id_site_id_occurred_at_index | tenant_id, site_id, occurred_at | no |
| integration_events | integration_events_tenant_provider_source_event_unique | tenant_id, provider, source_event_id | yes |
| integration_site_configs | integration_site_configs_tenant_id_index | tenant_id | no |
| integration_site_secrets | integration_site_secrets_tenant_id_index | tenant_id | no |
| integration_sync_logs | integration_sync_logs_tenant_id_index | tenant_id | no |
| integration_sync_logs | integration_sync_logs_tenant_id_provider_index | tenant_id, provider | no |
| integration_tenant_secrets | integration_tenant_secrets_tenant_id_index | tenant_id | no |
| integration_tenant_secrets | integration_tenant_secrets_tenant_id_provider_unique | tenant_id, provider | yes |
| integrations | integrations_tenant_id_index | tenant_id | no |
| integrations | integrations_tenant_id_provider_unique | tenant_id, provider | yes |
| it_provisioning_requests | it_prov_requests_tenant_status_idx | tenant_id, status | no |
| it_provisioning_requests | it_provisioning_requests_tenant_id_index | tenant_id | no |
| it_ticket_comments | it_ticket_comments_tenant_id_index | tenant_id | no |
| it_ticket_events | it_ticket_events_tenant_id_index | tenant_id | no |
| it_ticket_links | it_ticket_links_tenant_target_idx | tenant_id, linkable_type, linkable_id | no |
| it_tickets | it_tickets_tenant_assignee_status_idx | tenant_id, assigned_to_user_id, status | no |
| it_tickets | it_tickets_tenant_id_index | tenant_id | no |
| it_tickets | it_tickets_tenant_reference_uq | tenant_id, reference | yes |
| it_tickets | it_tickets_tenant_sla_state_idx | tenant_id, sla_state | no |
| it_tickets | it_tickets_tenant_status_idx | tenant_id, status | no |
| it_tickets | it_tickets_tenant_type_status_idx | tenant_id, work_type, status | no |
| location_hardware | location_hardware_tenant_id_index | tenant_id | no |
| monitoring_collectors | monitoring_collectors_tenant_status_idx | tenant_id, status | no |
| monitoring_collectors | monitoring_collectors_tenant_uuid_uq | tenant_id, collector_uuid | yes |
| monitoring_profiles | monitoring_profiles_tenant_name_uq | tenant_id, name | yes |
| monitors | monitors_tenant_state_idx | tenant_id, current_state | no |
| site_rooms | site_rooms_tenant_id_index | tenant_id | no |
