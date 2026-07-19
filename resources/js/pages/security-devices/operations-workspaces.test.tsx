import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { CollectorCard, type Collector } from './discovery';
import { MaintenanceCard, type MaintenanceRow } from './maintenance';
import {
    MonitoringContent,
    type MonitoringWorkspace,
    type MonitorRow,
} from './monitoring';

const remoteMonitor: MonitorRow = {
    id: 11,
    name: 'Remote SNMP',
    kind: 'snmp',
    reported_state: 'failed',
    effective_state: 'collection_unavailable',
    affects_availability: true,
    enabled: true,
    operational: true,
    suppressed_until: null,
    last_observation_at: '2026-07-19T08:00:00Z',
    freshness_state: 'stale',
    device: {
        id: 9,
        name: 'Remote switch',
        href: '/security-devices/devices/9',
        domain: 'it_infrastructure',
        category: 'networking',
    },
    site: {
        id: 4,
        name: 'Remote Site',
        href: '/security-devices/sites/4',
    },
    collection: {
        mode: 'remote_collector',
        collector_id: 3,
        collector_name: 'Remote collector',
        state: 'unavailable',
        last_seen_at: '2026-07-19T08:00:00Z',
    },
    latest_observation: null,
};

const collectionPath = {
    collector_id: 3,
    collector_name: 'Remote collector',
    state: 'unavailable',
    reported_status: 'offline',
    last_seen_at: '2026-07-19T08:00:00Z',
    heartbeat_lag_seconds: 600,
    site: {
        id: 4,
        name: 'Remote Site',
        href: '/security-devices/sites/4',
    },
    affected_monitors: 4,
    affected_devices: 3,
};

const monitoringWorkspace = {
    tabs: [{ key: 'findings', label: 'Findings' }],
    active_tab: 'findings',
    boundary: {
        title: 'Native monitoring',
        description: 'Direct first',
        privacy_note: 'No secrets',
        control_room_note: 'Control Room triage',
    },
    summary: {
        total_devices: 3,
        total_monitors: 4,
        enabled_monitors: 4,
        direct_monitors: 0,
        remote_monitors: 4,
        monitored_devices: 3,
        unmonitored_devices: 0,
        healthy: 0,
        degraded: 0,
        failed: 0,
        unknown: 0,
        stale: 0,
        pending: 0,
        paused: 0,
        collection_paths_unavailable: 1,
        active_findings: 1,
    },
    findings: {
        monitors: [],
        collection_paths: [collectionPath],
        note: 'Grouped once.',
    },
    monitors: [remoteMonitor],
    inventory: { total: 1, shown: 1, truncated: false },
    coverage: {
        total_devices: 3,
        monitored_devices: 3,
        missing_devices: 0,
        paused_monitors: 0,
        fresh: 0,
        stale: 4,
        never_observed: 0,
        unsupported_state: 'not_assessed',
        unsupported_note: 'Not assessed.',
        by_kind: { snmp: 4 },
        by_site: [],
    },
    dependencies: {
        canonical_model_available: false,
        note: 'No dependency graph.',
        collection_paths: [collectionPath],
    },
    trends: [],
    collection: {
        direct: { label: 'Main application', monitors: 0, devices: 0 },
        remote_paths: [collectionPath],
    },
    filters: {
        search: null,
        state: null,
        kind: null,
        site_id: null,
        device_id: null,
        collection_mode: null,
    },
    filter_options: { states: [], kinds: [], sites: [], devices: [] },
} satisfies MonitoringWorkspace;

describe('Security & Devices operations workspaces', () => {
    it('groups a collector outage without repeating downstream monitors as independent findings', () => {
        render(<MonitoringContent workspace={monitoringWorkspace} />);

        expect(screen.getByText('Remote collector')).toBeInTheDocument();
        expect(screen.getByText(/3 affected devices/)).toBeInTheDocument();
        expect(
            screen.getByText('No independent monitor findings'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Remote SNMP')).not.toBeInTheDocument();
    });

    it('shows maintenance provenance and canonical device and site links without exposing private notes', () => {
        const row: MaintenanceRow = {
            id: 8,
            type: 'calibration',
            status: 'scheduled',
            schedule_state: 'due_soon',
            description: 'Calibrate infusion pump',
            scheduled_for: '2026-07-22',
            completed_at: null,
            performed_by: null,
            vendor_reference: 'WO-42',
            device: {
                id: 2,
                name: 'Infusion pump',
                href: '/security-devices/devices/2',
                domain: 'iot_healthcare',
                category: 'medical_device',
            },
            site: {
                id: 1,
                name: 'Clinical Site',
                href: '/security-devices/sites/1',
            },
        };

        render(<MaintenanceCard row={row} canManage={false} />);

        expect(
            screen.getByRole('link', { name: 'Infusion pump' }),
        ).toHaveAttribute('href', '/security-devices/devices/2');
        expect(
            screen.getByRole('link', { name: 'Clinical Site' }),
        ).toHaveAttribute('href', '/security-devices/sites/1');
        expect(screen.getByText('Work reference WO-42')).toBeInTheDocument();
        expect(screen.queryByText(/notes/i)).not.toBeInTheDocument();
    });

    it('shows exact collector load and uncertainty without inventing capacity', () => {
        const collector: Collector = {
            id: 3,
            name: 'Remote collector',
            site: {
                id: 4,
                name: 'Remote Site',
                href: '/security-devices/sites/4',
            },
            reported_status: 'offline',
            freshness_state: 'unavailable',
            last_seen_at: '2026-07-19T08:00:00Z',
            heartbeat_lag_seconds: 600,
            monitor_load: 4,
            device_load: 3,
            affected_monitors: 4,
            affected_devices: 3,
            impact_note:
                'Downstream monitor results are uncertain until this collection path reports again.',
        };

        render(<CollectorCard collector={collector} />);

        expect(screen.getByText(/3 devices · 4 checks/)).toBeInTheDocument();
        expect(screen.getByText(/results are uncertain/)).toBeInTheDocument();
        expect(screen.queryByText(/%/)).not.toBeInTheDocument();
    });
});
