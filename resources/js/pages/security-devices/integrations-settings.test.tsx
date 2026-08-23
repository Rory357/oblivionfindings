import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { CredentialReferenceManagement } from '@/components/security-devices/credential-reference-management';
import {
    CredentialRotationStatus,
    ProviderCard,
    type Provider,
} from './integrations';
import {
    SiteCredentialsCard,
    type SiteCredentialRow,
} from './integrations/site-credentials';
import { AuditEvidence, type AuditEntry } from './settings';

describe('Security & Devices integrations and settings', () => {
    it('shows safe credential reference status and never renders an external secret path', () => {
        render(
            <CredentialReferenceManagement
                workspace={{
                    visible: true,
                    can_manage: true,
                    driver_state: 'configured',
                    driver_note:
                        'The external Vault lease issuer is configured.',
                    sites: [{ id: 42, name: 'Harbour House' }],
                    rows: [
                        {
                            reference_uuid:
                                '019f7b90-a3cc-7c6b-8428-766011e76005',
                            reference_key: 'vault:unifi/site-42',
                            site_id: 42,
                            site_name: 'Harbour House',
                            provider: 'unifi',
                            purpose: 'device_management',
                            capabilities: ['command:network.device.reboot'],
                            status: 'active',
                            rotation_status: 'current',
                            test_status: 'passed',
                            version: 3,
                            live_lease_count: 0,
                            pending_revoke_count: 0,
                            last_tested_at: '2026-07-24T01:00:00Z',
                            last_rotated_at: '2026-07-23T01:00:00Z',
                            revoked_at: null,
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Credential references')).toBeInTheDocument();
        expect(screen.getByText('vault:unifi/site-42')).toBeInTheDocument();
        expect(
            screen.getByText('command:network.device.reboot'),
        ).toBeInTheDocument();
        expect(screen.getByText('Test reference')).toBeInTheDocument();
        expect(
            screen.getByText('0 short-lived leases active'),
        ).toBeInTheDocument();
        expect(screen.getByText('No revocations pending')).toBeInTheDocument();
        expect(
            screen.queryByText('secret/data/sites/42/core-switch'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Add reference'));
        expect(
            screen.getByText(
                /Never paste a password, API key, token or private key/i,
            ),
        ).toBeInTheDocument();
    });
    it.each([
        ['current', 'Rotation current', 'default'],
        ['rotation_due', 'Rotation due', 'warning'],
        ['unknown', 'Rotation status unknown', 'secondary'],
        ['not_configured', 'Credential not configured', 'secondary'],
    ])('labels %s rotation state truthfully', (state, label, variant) => {
        render(
            <CredentialRotationStatus
                state={state}
                cadenceDays={90}
                data-testid="rotation"
            />,
        );
        expect(screen.getByTestId('rotation')).toHaveTextContent(label);
        expect(screen.getByTestId('rotation')).toHaveAttribute(
            'data-variant',
            variant,
        );
    });

    it('shows explicit provider exceptions and a reachable manager drill-down', () => {
        const provider: Provider = {
            slug: 'unifi',
            name: 'UniFi',
            vendor: 'Ubiquiti',
            summary: 'Network estate',
            device_scope: ['switches'],
            docs_href: '/security-devices/integrations/unifi',
            connection_status: 'connected',
            connected: true,
            last_tested_at: null,
            last_synced_at: '2026-07-18T00:00:00Z',
            device_count: 4,
            events_24h: 0,
            credential: {
                configured: true,
                reference: '0042',
                reference_label: 'Credential ending 0042',
                display_state: 'provider_connection_configured',
                rotation_state: 'rotation_due',
                rotation_cadence_days: 90,
                rotated_at: null,
                created_at: null,
                last_tested_at: null,
                site_credentials: {
                    total: 0,
                    enabled: 0,
                    needs_attention: 0,
                    capabilities: [],
                },
            },
            site_mapping: { total: 2, mapped: 1, unmapped: 1, sites: [] },
            sync: {
                status: 'failed',
                freshness: 'stale',
                last_synced_at: null,
                items_processed: 4,
                items_errored: 1,
                stale_site_count: 1,
                affected_site_count: 2,
                summary: 'The latest sync needs review.',
            },
            reconciliation: {
                imported_devices: 4,
                unassigned_devices: 1,
                duplicate_candidates: 0,
                unsupported_checks: 0,
            },
            monitoring_support: {
                state: 'supported',
                scope: 'provider',
                note: 'Provider evidence only.',
            },
            health: {
                state: 'stale',
                freshness: 'stale',
                last_attempted_at: '2026-07-18T00:00:00Z',
                last_collected_at: '2026-07-18T00:00:00Z',
                summary:
                    'At least one mapped Site has health evidence more than 24 hours old.',
                action: 'Review sync schedule',
                href: '/security-devices/integrations/unifi',
            },
            runtime: {
                version: '1.0',
                contract_state: 'topology_collection',
                contract_label: 'Inventory, sync, topology and events',
                contract_note: 'Typed topology contract is available.',
                capabilities: ['device_sync'],
                page_limit: 250,
                minimum_interval_seconds: 60,
                backfill_limit: 5000,
                cursor_scopes: 1,
                partial_scopes: 0,
                exception_count: 0,
                latest_completed_at: '2026-07-18T00:00:00Z',
                latest_exception_at: null,
                exception_codes: [],
                disconnect_ready: true,
                revoke_ready: true,
            },
            exceptions: [
                {
                    type: 'unmapped_site',
                    summary: '1 site mapping requires attention.',
                    action: 'Review site mappings',
                    href: '/security-devices/integrations/unifi',
                    count: 1,
                },
            ],
            exception_count: 1,
        };
        const { rerender } = render(
            <ProviderCard provider={provider} canManage />,
        );
        const capabilitySummary = screen.getByText(
            'Inventory, sync, topology and events',
        );
        expect(capabilitySummary).toBeInTheDocument();
        expect(capabilitySummary).toHaveClass(
            'max-w-full',
            'whitespace-normal',
        );
        expect(
            screen.getByText('Typed topology contract is available.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('1 site mapping requires attention.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Health feed')).toBeInTheDocument();
        expect(screen.getByText('Stale')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Review sync schedule' }),
        ).toHaveAttribute('href', '/security-devices/integrations/unifi');
        expect(
            screen.getByRole('link', { name: 'Review site mappings' }),
        ).toHaveAttribute('href', '/security-devices/integrations/unifi');
        expect(screen.getByText(/Rotation due/)).toBeInTheDocument();

        rerender(
            <ProviderCard
                provider={{
                    ...provider,
                    slug: 'queclink',
                    name: 'Queclink',
                    connection_status: 'unavailable',
                    connected: false,
                    health: {
                        state: 'unsupported',
                        freshness: 'unsupported',
                        last_attempted_at: null,
                        last_collected_at: null,
                        summary:
                            'This provider does not declare a typed health observation capability.',
                        action: 'Review provider support',
                        href: '/security-devices/integrations/queclink',
                    },
                    runtime: {
                        ...provider.runtime,
                        contract_state: 'native_runtime_only',
                        contract_label: 'Native operations only',
                        contract_note:
                            'No verified cloud API is enabled. Direct TCP intake, canonical tracking, and governed Device Management remain available.',
                    },
                }}
                canManage
            />,
        );
        expect(screen.getByText('Native operations only')).toBeInTheDocument();
        expect(screen.getByText('Cloud API unavailable')).toBeInTheDocument();
        expect(
            screen.getByText(
                /Direct TCP intake, canonical tracking, and governed Device Management remain available/i,
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Adapter scaffold')).not.toBeInTheDocument();
    });

    it('labels site-only credentials truthfully without inventing rotation evidence', () => {
        const provider = {
            slug: 'milesight',
            name: 'Milesight',
            vendor: 'Milesight IoT',
            summary: 'Sensors',
            device_scope: [],
            docs_href: '/security-devices/integrations/milesight',
            connection_status: 'untested',
            connected: false,
            last_tested_at: null,
            last_synced_at: null,
            device_count: 0,
            events_24h: 0,
            credential: {
                configured: true,
                reference: null,
                reference_label: null,
                display_state: 'site_credentials_configured',
                rotation_state: 'unknown',
                rotation_cadence_days: 90,
                rotated_at: null,
                created_at: null,
                last_tested_at: null,
                site_credentials: {
                    total: 1,
                    enabled: 1,
                    needs_attention: 0,
                    capabilities: ['gateway'],
                },
            },
            site_mapping: { total: 0, mapped: 0, unmapped: 0, sites: [] },
            sync: {
                status: 'not_run',
                freshness: 'never',
                last_synced_at: null,
                items_processed: 0,
                items_errored: 0,
                stale_site_count: 0,
                affected_site_count: 0,
                summary: null,
            },
            reconciliation: {
                imported_devices: 0,
                unassigned_devices: 0,
                duplicate_candidates: 0,
                unsupported_checks: 0,
            },
            monitoring_support: {
                state: 'capability_absent',
                scope: 'provider',
                note: 'Provider evidence only.',
            },
            health: {
                state: 'unsupported',
                freshness: 'unsupported',
                last_attempted_at: null,
                last_collected_at: null,
                summary:
                    'This provider does not declare a typed health observation capability.',
                action: 'Review provider support',
                href: '/security-devices/integrations/milesight',
            },
            runtime: {
                version: '1.1',
                contract_state: 'inventory_sync',
                contract_label: 'Inventory and sync',
                contract_note:
                    'Authenticated inventory and Device sync are available.',
                capabilities: [
                    'connection_health',
                    'inventory_discovery',
                    'device_sync',
                ],
                page_limit: 100,
                minimum_interval_seconds: 300,
                backfill_limit: 1000,
                cursor_scopes: 0,
                partial_scopes: 0,
                exception_count: 0,
                latest_completed_at: null,
                latest_exception_at: null,
                exception_codes: [],
                disconnect_ready: false,
                revoke_ready: false,
            },
            exceptions: [],
            exception_count: 0,
        } satisfies Provider;

        render(<ProviderCard provider={provider} canManage />);
        expect(screen.getByText('Not tested')).toBeInTheDocument();
        expect(screen.queryByText('Adapter scaffold')).not.toBeInTheDocument();
        expect(
            screen.getByText('Site credentials configured'),
        ).toBeInTheDocument();
        expect(screen.getByText('Rotation status unknown')).toBeInTheDocument();
        expect(
            screen.queryByText('Credential not configured'),
        ).not.toBeInTheDocument();
    });

    it('renders only bounded site credential state', () => {
        const rows: SiteCredentialRow[] = [
            {
                id: 7,
                site_id: 42,
                site_name: 'Harbour House',
                capability: 'gateway',
                enabled: true,
                state: 'error',
                failure_category: 'provider_failure',
                last_tested_at: '2026-07-20T00:00:00Z',
            },
            {
                id: 8,
                site_id: 42,
                site_name: 'Harbour House',
                capability: 'door_api',
                enabled: true,
                state: 'untested',
                failure_category: null,
                last_tested_at: null,
            },
            {
                id: 9,
                site_id: 42,
                site_name: 'Harbour House',
                capability: 'sensor_api',
                enabled: true,
                state: 'connected',
                failure_category: null,
                last_tested_at: '2026-07-20T00:00:00Z',
            },
        ];
        render(<SiteCredentialsCard rows={rows} />);
        expect(screen.getAllByText('Harbour House')).toHaveLength(3);
        expect(screen.getByText('Needs attention')).toBeInTheDocument();
        expect(screen.getByText('Not tested')).toBeInTheDocument();
        expect(screen.getByText('Connected')).toBeInTheDocument();
        expect(
            screen.queryByText(/RAW-|bearer|https?:\/\//i),
        ).not.toBeInTheDocument();
    });

    it('renders bounded audit evidence without raw detail controls', () => {
        const entries: AuditEntry[] = [
            {
                id: 1,
                action: 'device.update',
                actor: 'Alex',
                record_type: 'Device',
                record_reference: '#4',
                fields: ['name'],
                created_at: '2026-07-20T00:00:00Z',
            },
        ];
        render(<AuditEvidence visible entries={entries} />);
        expect(screen.getByText('device.update')).toBeInTheDocument();
        expect(screen.getByText('Changed: name')).toBeInTheDocument();
        expect(
            screen.queryByText(/IP address|user agent|before|after/i),
        ).not.toBeInTheDocument();
    });
});
