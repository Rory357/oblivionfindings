import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

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
            implementation_status: 'live',
            capabilities: ['network'],
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
                display_state: 'tenant_credential_configured',
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
                state: 'not_assessed',
                scope: 'provider',
                note: 'Provider evidence only.',
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
        render(<ProviderCard provider={provider} canManage />);
        expect(
            screen.getByText('1 site mapping requires attention.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Review site mappings' }),
        ).toHaveAttribute('href', '/security-devices/integrations/unifi');
        expect(screen.getByText(/Rotation due/)).toBeInTheDocument();
    });

    it('labels site-only credentials truthfully without inventing rotation evidence', () => {
        const provider = {
            slug: 'milesight',
            name: 'Milesight',
            vendor: 'Milesight IoT',
            summary: 'Sensors',
            implementation_status: 'scaffold',
            capabilities: [],
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
                state: 'not_assessed',
                scope: 'provider',
                note: 'Provider evidence only.',
            },
            exceptions: [],
            exception_count: 0,
        } satisfies Provider;

        render(<ProviderCard provider={provider} canManage />);
        expect(screen.getByText('Not tested')).toBeInTheDocument();
        expect(screen.getByText('Adapter scaffold')).toBeInTheDocument();
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
