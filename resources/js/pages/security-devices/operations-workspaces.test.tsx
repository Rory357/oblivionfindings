import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    CollectorCard,
    DiscoveryRunCard,
    type Collector,
    type DiscoveryRunRow,
} from './discovery';
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
        canonical_model_available: true,
        note: 'Dependency graph available.',
        suppressed_symptoms: 0,
        records: [],
        collection_paths: [collectionPath],
    },
    trends: [],
    collection: {
        direct: { label: 'Main application', monitors: 0, devices: 0 },
        direct_sites: [
            {
                site: {
                    id: 2,
                    name: 'Central Site',
                    href: '/security-devices/sites/2',
                },
                state: 'ready',
                proof_state: 'verified',
                label: 'Direct monitoring proven',
                note: 'The main application has current direct evidence for this Site; no collector is required.',
                devices: 3,
                monitored_devices: 2,
                unmonitored_devices: 1,
                direct_monitors: 4,
                direct_devices: 2,
                remote_monitors: 0,
                disabled_monitors: 0,
                durable_direct_evidence: 4,
                fresh: 4,
                stale: 0,
                never_observed: 0,
                attention: 0,
                evidence_at: '2026-07-19T08:00:00Z',
                evidence_age_seconds: 20,
                runtime: {
                    state: 'available',
                    available: 4,
                    required: 4,
                    components: {
                        orchestration: 'available',
                        checks: 'available',
                        events: 'available',
                        topology: 'available',
                    },
                },
                topology: {
                    state: 'current',
                    source: 'native:snmp',
                    captured_at: '2026-07-19T07:59:00Z',
                    node_count: 3,
                    edge_count: 2,
                    change_count: 0,
                },
                discovery: {
                    state: 'current',
                    scopes: 1,
                    completed_at: '2026-07-19T07:58:00Z',
                },
            },
        ],
        remote_paths: [collectionPath],
    },
    runtime: {
        state: 'attention',
        workers: {
            state: 'not_observed',
            available: 0,
            total: 8,
            attention: 0,
            not_observed: 8,
            note: 'No heartbeat evidence.',
        },
        queues: {
            events: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            checks: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            discovery: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            provider: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            topology: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            maintenance: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            orchestration: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
            commands: {
                state: 'scope_restricted',
                pending: null,
                oldest_age_seconds: null,
                dead_letters: null,
                worker_state: 'not_observed',
                heartbeat_age_seconds: null,
                dispatch_lag_seconds: null,
            },
        },
        listeners: {},
        external_heartbeat: {
            state: 'disabled',
            reason_code: null,
            last_sent_age_seconds: null,
            last_evaluated_age_seconds: null,
            note: 'Configure an independently hosted dead-man monitor for total outage detection.',
        },
        storage: {
            time_series: { state: 'not_configured', series: 0 },
            snapshots: { state: 'not_observed', records: 0, available: 0 },
        },
        collectors: {
            total: 1,
            available: 0,
            degraded: 0,
            unavailable: 1,
            revoked: 0,
            backlog_items: 0,
            gaps: 0,
        },
        observed_at: '2026-07-19T08:00:00Z',
    },
    delivery: {
        contracts: {
            envelope_current: 2,
            envelope_accepted: [1, 2],
            payloads: {
                observation: { current: 2, accepted: [1, 2] },
                event: { current: 2, accepted: [1, 2] },
            },
            commands: {
                standard_current: 6,
                break_glass_current: 7,
                accepted: [2, 3, 4, 5, 6, 7],
                retry_policy: 'reconcile_before_retry',
            },
        },
        dead_letters: {
            visible: false,
            total: null,
            shown: 0,
            truncated: false,
            rows: [],
            note: 'Recovery restricted.',
        },
    },
    storage: {
        time_series: {
            state: 'not_configured',
            series: 0,
            available: 0,
            missing: 0,
            unavailable: 0,
            capacity_evidence: [],
        },
        retention: {
            policies: [],
            explanation: 'Most restrictive policy applies.',
        },
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
            screen.getByRole('link', { name: 'Remote Site' }),
        ).toHaveAttribute('href', '/security-devices/sites/4');
        expect(
            screen.getByText('No independent monitor findings'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Remote SNMP')).not.toBeInTheDocument();
    });

    it('shows every isolated worker while keeping global queue counts restricted', () => {
        render(
            <MonitoringContent
                workspace={{ ...monitoringWorkspace, active_tab: 'overview' }}
            />,
        );

        expect(screen.getByText('Commands')).toBeInTheDocument();
        expect(screen.getByText('Orchestration')).toBeInTheDocument();
        expect(screen.getAllByText('Counts restricted')).toHaveLength(8);
        expect(screen.getAllByText(/Worker Not Observed/)).toHaveLength(8);
        expect(screen.getByText('No heartbeat evidence.')).toBeInTheDocument();
        expect(
            screen.getByText('Independent outage watchdog'),
        ).toBeInTheDocument();
        expect(screen.getByText('Disabled')).toBeInTheDocument();
    });

    it('links monitoring coverage back to the canonical Site workspace', () => {
        render(
            <MonitoringContent
                workspace={{
                    ...monitoringWorkspace,
                    active_tab: 'coverage',
                    coverage: {
                        ...monitoringWorkspace.coverage,
                        by_site: [
                            {
                                site: { id: 4, name: 'Remote Site' },
                                devices: 3,
                                monitored_devices: 3,
                                missing_devices: 0,
                            },
                        ],
                    },
                }}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Remote Site' }),
        ).toHaveAttribute('href', '/security-devices/sites/4');
    });

    it('explains backward-compatible delivery and command retry safety in data collection', () => {
        render(
            <MonitoringContent
                workspace={{
                    ...monitoringWorkspace,
                    active_tab: 'collection',
                }}
            />,
        );

        expect(
            screen.getByText('Delivery contracts & recovery'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Current v2')).toHaveLength(2);
        expect(screen.getAllByText(/Accepts v1, 2/)).toHaveLength(2);
        expect(
            screen.getByText(/Reconcile actual state before any retry/),
        ).toBeInTheDocument();
        expect(screen.getByText('Recovery restricted')).toBeInTheDocument();
    });

    it('shows Site-specific proof that central monitoring works without a collector', () => {
        render(
            <MonitoringContent
                workspace={{
                    ...monitoringWorkspace,
                    active_tab: 'collection',
                }}
            />,
        );

        expect(screen.getByText('Central Site readiness')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Central Site' }),
        ).toHaveAttribute('href', '/security-devices/sites/2');
        expect(screen.getByText('Central path verified')).toBeInTheDocument();
        expect(screen.getByText('Runtime Available')).toBeInTheDocument();
        expect(screen.getByText('Topology Current')).toBeInTheDocument();
        expect(
            screen.getByText(/Latest durable central evidence/),
        ).toBeInTheDocument();
        expect(screen.getByText('1 Device needs coverage')).toBeInTheDocument();
    });

    it('does not present incomplete topology or discovery evidence as a verified central path', () => {
        const incomplete = {
            ...monitoringWorkspace.collection.direct_sites[0],
            state: 'evidence_incomplete',
            proof_state: 'not_verified',
            label: 'Direct monitoring proof incomplete',
            note: 'The direct check is current, but topology and discovery evidence must also be current before this Site is release-ready.',
            topology: {
                ...monitoringWorkspace.collection.direct_sites[0].topology,
                state: 'stale',
            },
        };

        render(
            <MonitoringContent
                workspace={{
                    ...monitoringWorkspace,
                    active_tab: 'collection',
                    collection: {
                        ...monitoringWorkspace.collection,
                        direct_sites: [incomplete],
                    },
                }}
            />,
        );

        expect(
            screen.getByText('Direct monitoring proof incomplete'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Central path not verified'),
        ).toBeInTheDocument();
        expect(screen.getByText('Topology Stale')).toBeInTheDocument();
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

    it('makes remote discovery handoff and returned progress explicit', () => {
        const run: DiscoveryRunRow = {
            id: 51,
            run_uuid: '018f0000-0000-7000-8000-000000000951',
            scope_id: 7,
            scope_name: 'Remote clinical network',
            status: 'running',
            collection_mode: 'remote_collector',
            collector: { id: 3, name: 'Remote collector', state: 'available' },
            trigger: 'manual:user:7',
            planned: 20,
            returned: 12,
            pending: 8,
            found: 0,
            matched: 0,
            proposed: 0,
            changed: 0,
            excluded: 0,
            failed: 0,
            unresolved: 0,
            started_at: '2026-07-27T08:00:00Z',
            completed_at: null,
        };

        render(<DiscoveryRunCard run={run} />);

        expect(screen.getByText('Remote clinical network')).toBeInTheDocument();
        expect(
            screen.getByText(/Remote path · Remote collector/),
        ).toBeInTheDocument();
        expect(screen.getByText('Collector Available')).toBeInTheDocument();
        expect(screen.getByText('Running')).toBeInTheDocument();
        expect(
            screen.getByText('12 of 20 results returned'),
        ).toBeInTheDocument();
        expect(screen.getByText(/8 targets remain/)).toBeInTheDocument();
        expect(
            screen.getByText(/ordered encrypted buffer/),
        ).toBeInTheDocument();
    });

    it('shows one evidence-backed root dependency and keeps the suppressed symptom inspectable', () => {
        render(
            <MonitoringContent
                workspace={{
                    ...monitoringWorkspace,
                    active_tab: 'dependencies',
                    dependencies: {
                        ...monitoringWorkspace.dependencies,
                        suppressed_symptoms: 1,
                        records: [
                            {
                                id: 81,
                                policy: 'suppress_notifications_and_ticketing',
                                source: 'topology',
                                confidence: 0.95,
                                review_state: 'inferred',
                                site: {
                                    id: 4,
                                    name: 'Remote Site',
                                    href: '/security-devices/sites/4',
                                },
                                upstream: {
                                    id: 10,
                                    name: 'WAN path failed',
                                    state: 'failed',
                                    device_href: '/security-devices/devices/10',
                                },
                                downstream: {
                                    id: 11,
                                    name: 'Remote switch path',
                                    state: 'suppressed',
                                    suppression_reason: 'dependency_failed',
                                    device_href: '/security-devices/devices/11',
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        expect(screen.getByText(/WAN path failed/)).toBeInTheDocument();
        expect(screen.getByText(/Remote switch path/)).toBeInTheDocument();
        expect(screen.getByText('95% confidence')).toBeInTheDocument();
        expect(screen.getByText(/1 suppressed symptoms/)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /unlock|restart|wipe|run command/i,
            }),
        ).not.toBeInTheDocument();
    });

    it('explains retained capacity evidence and an honest forecast state', () => {
        render(
            <MonitoringContent
                workspace={{
                    ...monitoringWorkspace,
                    active_tab: 'trends',
                    storage: {
                        time_series: {
                            state: 'available',
                            series: 1,
                            available: 1,
                            missing: 0,
                            unavailable: 0,
                            capacity_evidence: [
                                {
                                    series_id: 42,
                                    metric: 'interface.utilisation',
                                    unit: 'percent',
                                    tier: 'raw',
                                    device: {
                                        id: 9,
                                        name: 'Remote switch',
                                        href: '/security-devices/devices/9',
                                    },
                                    value: '82.500000',
                                    p95: 86.2,
                                    sample_count: 24,
                                    observed_at: '2026-07-19T08:00:00Z',
                                    storage_state: 'available',
                                    projection: {
                                        state: 'forecast',
                                        threshold: 90,
                                        measured_from: '2026-07-01T08:00:00Z',
                                        measured_to: '2026-07-19T08:00:00Z',
                                        sample_count: 24,
                                        p95: 86.2,
                                        slope_per_day: 0.2,
                                        confidence: 0.91,
                                        threshold_at: '2026-08-10T08:00:00Z',
                                    },
                                },
                            ],
                        },
                        retention: {
                            policies: [
                                {
                                    id: 1,
                                    name: 'Native monitoring default',
                                    scope: 'application',
                                    data_class: null,
                                    privacy_class: null,
                                    raw_days: 14,
                                    hourly_days: 180,
                                    daily_days: 1825,
                                    legal_hold: false,
                                },
                            ],
                            explanation:
                                'The most restrictive matching policy applies.',
                        },
                    },
                }}
            />,
        );

        expect(screen.getByText(/p95 86.2/)).toBeInTheDocument();
        expect(screen.getByText(/Projection Forecast/)).toBeInTheDocument();
        expect(screen.getByText(/91% confidence/)).toBeInTheDocument();
        expect(screen.getByText(/raw 14d/)).toBeInTheDocument();
    });

    it('links the root finding to Control Room and the technician-owned IT incident', () => {
        render(
            <MonitoringContent
                workspace={{
                    ...monitoringWorkspace,
                    active_tab: 'overview',
                    monitors: [
                        {
                            ...remoteMonitor,
                            correlation: {
                                control_room: {
                                    id: 31,
                                    reference: 'ALT-000031',
                                    status: 'resolved',
                                    href: '/control-room/alerts/31',
                                },
                                it_incident: {
                                    id: 44,
                                    reference: 'IT-000044',
                                    title: 'Remote path unavailable',
                                    status: 'in_progress',
                                    monitoring_recovered_at:
                                        '2026-07-19T09:00:00Z',
                                    href: '/it/tickets/44',
                                },
                            },
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByRole('link', {
                name: /Review Control Room correlation ALT-000031/,
            }),
        ).toHaveAttribute('href', '/control-room/alerts/31');
        expect(
            screen.getByRole('link', {
                name: /IT incident IT-000044.*technician closure pending/,
            }),
        ).toHaveAttribute('href', '/it/tickets/44');
    });
});
